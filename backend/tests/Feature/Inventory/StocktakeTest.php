<?php

namespace Tests\Feature\Inventory;

use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/**
 * Kiểm kê kho: bắt đầu (khóa kho + chụp tồn theo sản phẩm và lô), ghi số đếm (đơn vị bất kỳ), hoàn tất (MỘT phiếu điều chỉnh `count_correction`, không cần duyệt)
 * hoặc hủy. Kiểm kê không bao giờ sửa tồn trực tiếp: mọi chênh lệch đi qua StockService nên sổ kho vẫn bất biến.
 */
class StocktakeTest extends TestCase
{
    use AdministersBackups, CreatesTenants, MakesCatalog, MakesStock, ReadsAuditLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    /**
     * Một cửa hàng có kho với: phân NPK-16 (500 Kg, không lô), thuốc TS-01 (hai lô: L001 10 Kg, L002 5 Kg), phân URE-01 (chưa có tồn). Giá nhập 1.000 đ/Kg.
     *
     * @return array{0: object, 1: object, 2: object, 3: object, 4: object, 5: Warehouse, 6: array<string, mixed>, 7: array<string, mixed>, 8: array<string, mixed>} tenant, owner, branch, kg, bao, kho, npk, drug, ure
     */
    private function world(array $tenant = []): array
    {
        [$t, $owner, $branch] = $this->makeTenant($tenant);
        $kg = $this->makeUnit($t, 'Kg');
        $bao = $this->makeUnit($t, 'Bao');
        $kho = $this->warehouseOf($branch);
        $npk = $this->makeProduct($owner, $branch, $kg, $bao, 'NPK-16');
        $drug = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-01', true);
        $ure = $this->makeProduct($owner, $branch, $kg, $bao, 'URE-01');

        $this->postStock($owner, $kho, 'receipt', [
            ['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 500, 'unit_cost' => 1000],
            ['product_id' => $drug['id'], 'unit_id' => $kg->uuid, 'quantity' => 10, 'unit_cost' => 1000, 'batch_no' => 'L001', 'expires_at' => '2030-01-31'],
            ['product_id' => $drug['id'], 'unit_id' => $kg->uuid, 'quantity' => 5, 'unit_cost' => 1000, 'batch_no' => 'L002', 'expires_at' => '2030-06-30'],
        ])->assertCreated();

        return [$t, $owner, $branch, $kg, $bao, $kho, $npk, $drug, $ure];
    }

    private function start(object $user, Warehouse $kho, array $extra = []): TestResponse
    {
        return $this->api($user)->postJson('/api/v1/stocktakes', array_merge(['warehouse_id' => $kho->uuid], $extra));
    }

    /** @return list<array<string, mixed>> các dòng đếm của đợt (trang đầu, 100 dòng). */
    private function lines(object $user, string $id, string $query = ''): array
    {
        return $this->api($user)->getJson("/api/v1/stocktakes/{$id}/lines?per_page=100{$query}")->assertOk()->json('data');
    }

    /** Id dòng đếm theo mã sản phẩm (và số lô nếu có). */
    private function lineId(object $user, string $id, string $sku, ?string $batchNo = null): string
    {
        foreach ($this->lines($user, $id) as $line) {
            if ($line['product']['sku'] === $sku && ($line['batch']['batch_no'] ?? null) === $batchNo) {
                return $line['id'];
            }
        }

        $this->fail("Không có dòng đếm {$sku} {$batchNo}");
    }

    private function countLine(object $user, string $id, string $lineId, mixed $quantity, ?string $unitId): TestResponse
    {
        return $this->api($user)->patchJson("/api/v1/stocktakes/{$id}/lines/{$lineId}", ['quantity' => $quantity, 'unit_id' => $unitId]);
    }

    private function balanceOf(Warehouse $kho, string $productUuid, ?string $batchNo = null): string
    {
        return $this->platform(fn () => (string) StockBalance::where('warehouse_id', $kho->id)
            ->whereIn('product_id', DB::table('products')->where('uuid', $productUuid)->select('id'))
            ->when($batchNo, fn ($q) => $q->whereIn('batch_id', DB::table('batches')->whereRaw('lower(batch_no) = ?', [strtolower($batchNo)])->select('id')))
            ->sum('quantity'));
    }

    // --- bắt đầu và chụp tồn ------------------------------------------------------------------

    public function test_starting_snapshots_the_stock_of_the_warehouse_per_product_and_lot(): void
    {
        [, $owner, , $kg, , $kho] = $this->world();

        $response = $this->start($owner, $kho, ['note' => 'Kiểm kê cuối tháng'])->assertCreated();
        $response->assertJsonPath('data.code', 'KK0001')->assertJsonPath('data.status', 'counting')->assertJsonPath('data.note', 'Kiểm kê cuối tháng')
            ->assertJsonPath('data.warehouse.code', 'KHO-01')->assertJsonPath('data.started_by.id', $owner->uuid)->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.adjustment_document', null)->assertJsonPath('data.summary', ['lines' => 3, 'counted' => 0, 'differing' => 0]);

        $id = $response->json('data.id');
        $lines = $this->lines($owner, $id);

        // Sắp theo tên sản phẩm rồi hạn dùng của lô; sản phẩm chưa có tồn (URE-01) không có dòng.
        $this->assertSame(['NPK-16', 'TS-01', 'TS-01'], array_column(array_column($lines, 'product'), 'sku'));
        $this->assertSame(['500.000', '10.000', '5.000'], array_column($lines, 'system_quantity'));
        $this->assertSame([null, 'L001', 'L002'], array_map(fn ($l) => $l['batch']['batch_no'] ?? null, $lines));
        $this->assertSame('2030-01-31', $lines[1]['batch']['expires_at']);
        $this->assertTrue($lines[1]['product']['track_batch']);
        $this->assertNull($lines[0]['counted']);
        $this->assertNull($lines[0]['difference']);
        $this->assertSame(['Kg', 'Bao'], array_map(fn ($u) => $u['unit']['name'], $lines[0]['product']['units']), 'có đủ đơn vị để đếm bằng đơn vị tiện nhất');
        $this->assertSame('50', $lines[0]['product']['units'][1]['factor']);

        $audit = $this->lastAudit(['module' => 'inventory', 'action' => 'stocktake_started']);
        $this->assertNotNull($audit);
        $this->assertSame(3, $audit->after['lines']);
    }

    public function test_the_snapshot_ignores_empty_balances_other_warehouses_and_deleted_products(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho, $npk, $drug] = $this->world();
        $kho2 = $this->makeWarehouse($branch);
        $ure = $this->makeProduct($owner, $branch, $kg, $bao, 'DAY-01');
        $this->postStock($owner, $kho2, 'receipt', [['product_id' => $ure['id'], 'unit_id' => $kg->uuid, 'quantity' => 7, 'unit_cost' => 1000]])->assertCreated();
        // Xuất hết tồn của NPK-16: số dư 0 không thành dòng đếm.
        $this->postStock($owner, $kho, 'issue', [['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 500]], ['reason' => 'damaged'])->assertCreated();
        // Sản phẩm bị xóa khỏi danh mục: không đếm được nữa.
        $this->api($owner)->deleteJson("/api/v1/products/{$drug['id']}")->assertNoContent();

        $id = $this->start($owner, $kho)->assertCreated()->assertJsonPath('data.summary.lines', 0)->json('data.id');
        $this->assertSame([], $this->lines($owner, $id));
        $this->assertSame(1, $this->platform(fn () => Stocktake::count()));
    }

    public function test_a_warehouse_can_have_only_one_counting_stocktake_but_other_warehouses_can_count_at_the_same_time(): void
    {
        [, $owner, $branch, , , $kho] = $this->world();
        $kho2 = $this->makeWarehouse($branch);

        $this->start($owner, $kho)->assertCreated()->assertJsonPath('data.code', 'KK0001');
        $this->start($owner, $kho)->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id'])
            ->assertJsonPath('errors.warehouse_id.0', 'Kho này đang có đợt kiểm kê chưa kết thúc (KK0001).');
        $this->start($owner, $kho2)->assertCreated()->assertJsonPath('data.code', 'KK0002');

        // Lớp cuối ở database: mỗi kho chỉ có một đợt đang đếm, kể cả khi hai yêu cầu cùng lúc.
        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('stocktakes')->insert([
            'tenant_id' => $kho->tenant_id, 'branch_id' => $kho->branch_id, 'warehouse_id' => $kho->id, 'code' => 'KK9999', 'status' => 'counting', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_an_inactive_warehouse_cannot_be_counted(): void
    {
        [, $owner, , , , $kho] = $this->world();
        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['status' => 'inactive'])->assertOk();

        $this->start($owner, $kho)->assertUnprocessable()->assertJsonPath('errors.warehouse_id.0', 'Kho này đã ngừng hoạt động.');
    }

    // --- khóa kho -----------------------------------------------------------------------------

    public function test_a_counting_warehouse_is_locked_for_every_other_stock_document_until_it_is_cancelled(): void
    {
        [, $owner, $branch, $kg, , $kho, $npk] = $this->world();
        $kho2 = $this->makeWarehouse($branch);
        $id = $this->start($owner, $kho)->assertCreated()->json('data.id');
        $line = fn (array $extra = []) => array_merge(['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 1], $extra);

        foreach ([
            ['receipt', $line(['unit_cost' => 1000]), []],
            ['issue', $line(), ['reason' => 'damaged']],
            ['adjustment', $line(['quantity' => -1]), ['reason' => 'count_correction']],
            ['opening', $line(['unit_cost' => 1000]), []],
        ] as [$type, $row, $extra]) {
            $this->postStock($owner, $kho, $type, [$row], $extra)->assertUnprocessable()->assertJsonPath('errors.warehouse_id.0', 'Kho này đang kiểm kê (KK0001): hoàn tất hoặc hủy đợt kiểm kê trước khi ghi phiếu kho.');
        }

        $this->assertSame('500.000', $this->balanceOf($kho, $npk['id']), 'tồn không đổi khi bị từ chối');
        $this->postStock($owner, $kho2, 'receipt', [$line(['unit_cost' => 1000])])->assertCreated(); // kho khác không bị khóa

        $warehouses = collect($this->api($owner)->getJson('/api/v1/warehouses')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['id' => $id, 'code' => 'KK0001'], $warehouses['KHO-01']['stocktake']);
        $this->assertNull($warehouses['KHO-02']['stocktake']);

        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertOk();
        $this->postStock($owner, $kho, 'receipt', [$line(['unit_cost' => 1000])])->assertCreated();
        $this->assertNull($this->api($owner)->getJson("/api/v1/warehouses/{$kho->uuid}")->json('data.stocktake'));
    }

    public function test_a_warehouse_that_is_counting_cannot_be_deactivated_or_deleted(): void
    {
        [, $owner, $branch, , , $kho] = $this->world();
        $empty = $this->makeWarehouse($branch);
        $this->start($owner, $kho)->assertCreated();
        $this->start($owner, $empty)->assertCreated();

        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['status' => 'inactive'])->assertStatus(409)->assertJsonPath('title', 'Kho đang kiểm kê.');
        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['name' => 'Kho đổi tên'])->assertOk(); // sửa thông tin khác vẫn được
        // Kho chưa có phiếu nhưng đã có đợt kiểm kê: không xóa được (sổ kiểm kê tham chiếu).
        $this->api($owner)->deleteJson("/api/v1/warehouses/{$empty->uuid}")->assertStatus(409)->assertJsonPath('title', 'Kho đã có đợt kiểm kê.');
    }

    // --- ghi số đếm ---------------------------------------------------------------------------

    public function test_counting_converts_the_unit_accepts_zero_and_can_be_cleared(): void
    {
        [, $owner, , $kg, $bao, $kho] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $npk = $this->lineId($owner, $id, 'NPK-16');
        $show = fn () => $this->api($owner)->getJson("/api/v1/stocktakes/{$id}")->assertOk()->json('data.summary');

        // Đếm 8 Bao = 400 Kg (tồn hệ thống 500 Kg): thiếu 100 Kg.
        $this->countLine($owner, $id, $npk, 8, $bao->uuid)->assertOk()
            ->assertJsonPath('data.counted.quantity', '400.000')->assertJsonPath('data.counted.entered.quantity', '8.000')->assertJsonPath('data.counted.entered.unit.name', 'Bao')
            ->assertJsonPath('data.counted.entered.factor', '50')->assertJsonPath('data.difference', '-100.000')->assertJsonPath('data.system_quantity', '500.000');
        $this->assertSame(['lines' => 3, 'counted' => 1, 'differing' => 1], $show());

        // Đếm đúng bằng tồn hệ thống: đã đếm nhưng không lệch.
        $this->countLine($owner, $id, $npk, 500, $kg->uuid)->assertOk()->assertJsonPath('data.difference', '0.000');
        $this->assertSame(['lines' => 3, 'counted' => 1, 'differing' => 0], $show());

        // Đếm 0 là hợp lệ (hết hàng).
        $this->countLine($owner, $id, $npk, 0, $kg->uuid)->assertOk()->assertJsonPath('data.counted.quantity', '0.000')->assertJsonPath('data.difference', '-500.000');

        // Xóa số đếm: về "chưa đếm".
        $this->countLine($owner, $id, $npk, null, null)->assertOk()->assertJsonPath('data.counted', null)->assertJsonPath('data.difference', null);
        $this->assertSame(['lines' => 3, 'counted' => 0, 'differing' => 0], $show());
    }

    public function test_the_count_is_validated(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho] = $this->world();
        $other = $this->makeUnit($tenant, 'Thùng'); // đơn vị có trong doanh nghiệp nhưng không thuộc sản phẩm
        $id = $this->start($owner, $kho)->json('data.id');
        $npk = $this->lineId($owner, $id, 'NPK-16');

        $this->countLine($owner, $id, $npk, -1, $kg->uuid)->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->countLine($owner, $id, $npk, 1.2345, $kg->uuid)->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->countLine($owner, $id, $npk, 'abc', $kg->uuid)->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->countLine($owner, $id, $npk, 5, null)->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
        $this->countLine($owner, $id, $npk, 5, $other->uuid)->assertUnprocessable()->assertJsonPath('errors.unit_id.0', 'Đơn vị này không thuộc sản phẩm.');
        $this->api($owner)->patchJson("/api/v1/stocktakes/{$id}/lines/{$npk}", [])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

        // Dòng của đợt khác (hoặc không có) là 404.
        $this->countLine($owner, $id, '00000000-0000-4000-8000-000000000000', 1, $kg->uuid)->assertNotFound();
        $this->assertSame(0, $this->platform(fn () => StocktakeLine::whereNotNull('counted_quantity')->count()));
    }

    // --- hoàn tất -----------------------------------------------------------------------------

    public function test_completing_posts_one_count_correction_with_signed_differences_per_lot(): void
    {
        [, $owner, , $kg, $bao, $kho, $npk, $drug, $ure] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');

        $this->countLine($owner, $id, $this->lineId($owner, $id, 'NPK-16'), 8, $bao->uuid)->assertOk();        // 400 Kg: thiếu 100
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'TS-01', 'L001'), 12, $kg->uuid)->assertOk();    // 12 Kg: thừa 2
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'TS-01', 'L002'), 5, $kg->uuid)->assertOk();     // đúng: không lệch
        // Giá trị chênh lệch trước khi hoàn tất, theo giá vốn bình quân hiện tại: (−100 + 2) × 1.000 đ.
        $this->api($owner)->getJson("/api/v1/stocktakes/{$id}")->assertJsonPath('data.difference_value', '-98000.00')->assertJsonPath('data.summary', ['lines' => 3, 'counted' => 3, 'differing' => 2]);

        $done = $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertOk();
        $done->assertJsonPath('data.status', 'completed')->assertJsonPath('data.finished_by.id', $owner->uuid)->assertJsonPath('data.adjustment_document.code', 'DC0001')
            ->assertJsonPath('data.difference_value', '-98000.00');
        $this->assertNotNull($done->json('data.finished_at'));

        // MỘT phiếu điều chỉnh, lý do kiểm kê, chỉ có các dòng lệch.
        $document = $this->api($owner)->getJson('/api/v1/stock/documents/'.$done->json('data.adjustment_document.id'))->assertOk()->json('data');
        $this->assertSame('adjustment', $document['type']);
        $this->assertSame('count_correction', $document['reason']);
        $this->assertSame('Kiểm kê KK0001', $document['note']);
        $this->assertCount(2, $document['lines']);
        $this->assertSame(['-100.000', '2.000'], array_column($document['lines'], 'quantity_delta'));
        $this->assertSame([null, 'L001'], array_map(fn ($l) => $l['batch']['batch_no'] ?? null, $document['lines']));
        $this->assertSame(2, $this->platform(fn () => StockDocument::count()), 'chỉ phiếu nhập ban đầu + MỘT phiếu điều chỉnh');

        // Tồn sửa đúng; dòng chưa có trong đợt (URE-01) và dòng không lệch giữ nguyên.
        $this->assertSame('400.000', $this->balanceOf($kho, $npk['id']));
        $this->assertSame('12.000', $this->balanceOf($kho, $drug['id'], 'L001'));
        $this->assertSame('5.000', $this->balanceOf($kho, $drug['id'], 'L002'));
        $this->assertSame('0', $this->balanceOf($kho, $ure['id']));

        $audit = $this->lastAudit(['module' => 'inventory', 'action' => 'stocktake_completed']);
        $this->assertEquals(['code' => 'KK0001', 'warehouse' => 'KHO-01', 'counted' => 3, 'adjusted' => 2, 'document' => 'DC0001'], $audit->after);

        // Đợt đã đóng: không đếm, hoàn tất hay hủy lại được; kho được mở khóa.
        $line = $this->lineId($owner, $id, 'NPK-16');
        $this->countLine($owner, $id, $line, 1, $kg->uuid)->assertUnprocessable()->assertJsonPath('errors.stocktake.0', 'Đợt kiểm kê KK0001 đã hoàn tất, không thay đổi được nữa.');
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertUnprocessable();
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertUnprocessable();
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();
    }

    public function test_uncounted_lines_keep_their_stock_and_counting_zero_removes_it(): void
    {
        [, $owner, , $kg, , $kho, $npk, $drug] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');

        $this->countLine($owner, $id, $this->lineId($owner, $id, 'TS-01', 'L002'), 0, $kg->uuid)->assertOk(); // hết lô L002
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertOk()->assertJsonPath('data.summary', ['lines' => 3, 'counted' => 1, 'differing' => 1]);

        $this->assertSame('500.000', $this->balanceOf($kho, $npk['id']), 'dòng chưa đếm giữ nguyên');
        $this->assertSame('10.000', $this->balanceOf($kho, $drug['id'], 'L001'));
        $this->assertSame('0.000', $this->balanceOf($kho, $drug['id'], 'L002'));
    }

    public function test_completing_without_any_difference_creates_no_document_and_unlocks_the_warehouse(): void
    {
        [, $owner, , $kg, , $kho, $npk] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $documents = $this->platform(fn () => StockDocument::count());

        $this->countLine($owner, $id, $this->lineId($owner, $id, 'NPK-16'), 500, $kg->uuid)->assertOk();
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.adjustment_document', null)->assertJsonPath('data.difference_value', '0.00');

        $this->assertSame($documents, $this->platform(fn () => StockDocument::count()));
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();

        // Hoàn tất mà chưa đếm dòng nào cũng được: không có gì để điều chỉnh.
        $second = $this->start($owner, $kho)->assertCreated()->json('data.id');
        $this->api($owner)->postJson("/api/v1/stocktakes/{$second}/complete")->assertOk()->assertJsonPath('data.adjustment_document', null);
    }

    public function test_a_product_deleted_while_counting_is_skipped_when_completing(): void
    {
        [, $owner, , $kg, , $kho, $npk, $drug] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'NPK-16'), 10, $kg->uuid)->assertOk();
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'TS-01', 'L001'), 7, $kg->uuid)->assertOk();
        $this->api($owner)->deleteJson("/api/v1/products/{$npk['id']}")->assertNoContent();

        $this->assertSame(['TS-01', 'TS-01'], array_column(array_column($this->lines($owner, $id), 'product'), 'sku'), 'dòng của sản phẩm đã xóa không còn hiện');
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertOk();

        $this->assertSame('500.000', $this->balanceOf($kho, $npk['id']), 'sản phẩm đã xóa không bị điều chỉnh');
        $this->assertSame('7.000', $this->balanceOf($kho, $drug['id'], 'L001'));
    }

    // --- hủy ----------------------------------------------------------------------------------

    public function test_cancelling_leaves_the_stock_untouched_and_unlocks_the_warehouse(): void
    {
        [, $owner, , $kg, , $kho, $npk] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'NPK-16'), 3, $kg->uuid)->assertOk();
        $documents = $this->platform(fn () => StockDocument::count());

        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.finished_by.id', $owner->uuid)->assertJsonPath('data.adjustment_document', null)
            ->assertJsonMissingPath('data.difference_value');

        $this->assertSame('500.000', $this->balanceOf($kho, $npk['id']));
        $this->assertSame($documents, $this->platform(fn () => StockDocument::count()));
        $this->assertNotNull($this->lastAudit(['module' => 'inventory', 'action' => 'stocktake_cancelled']));
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertUnprocessable();
        $this->api($owner)->postJson("/api/v1/stocktakes/{$id}/complete")->assertUnprocessable();

        // Kho được đếm lại từ đầu (đợt mới, số đếm cũ không mang sang).
        $again = $this->start($owner, $kho)->assertCreated()->assertJsonPath('data.code', 'KK0002')->assertJsonPath('data.summary.counted', 0)->json('data.id');
        $this->assertNotSame($id, $again);
    }

    // --- danh sách, lọc, phân trang -----------------------------------------------------------

    public function test_the_list_filters_by_status_and_warehouse_and_the_lines_by_search_and_state(): void
    {
        [, $owner, $branch, $kg, , $kho] = $this->world();
        $kho2 = $this->makeWarehouse($branch);
        $first = $this->start($owner, $kho)->json('data.id');
        $this->api($owner)->postJson("/api/v1/stocktakes/{$first}/cancel")->assertOk();
        $second = $this->start($owner, $kho)->json('data.id');
        $third = $this->start($owner, $kho2)->json('data.id');

        $codes = fn (string $query) => array_column($this->api($owner)->getJson("/api/v1/stocktakes{$query}")->assertOk()->json('data'), 'code');
        $this->assertSame(['KK0003', 'KK0002', 'KK0001'], $codes(''), 'mới nhất trước');
        $this->assertSame(['KK0003', 'KK0002'], $codes('?status=counting'));
        $this->assertSame(['KK0001'], $codes('?status=cancelled'));
        $this->assertSame(['KK0002', 'KK0001'], $codes('?warehouse='.$kho->uuid));
        $this->assertSame([], $codes('?status=completed'));
        $this->api($owner)->getJson('/api/v1/stocktakes?status=lạ')->assertUnprocessable();

        // Dòng: tìm theo tên, mã, số lô; lọc theo trạng thái đếm; phân trang.
        $this->countLine($owner, $second, $this->lineId($owner, $second, 'NPK-16'), 400, $kg->uuid)->assertOk();
        $this->countLine($owner, $second, $this->lineId($owner, $second, 'TS-01', 'L001'), 10, $kg->uuid)->assertOk();
        $skus = fn (string $q) => array_map(fn ($l) => $l['product']['sku'].($l['batch'] ? '/'.$l['batch']['batch_no'] : ''), $this->lines($owner, $second, $q));
        $this->assertSame(['NPK-16'], $skus('&q=npk'));
        $this->assertSame(['TS-01/L001', 'TS-01/L002'], $skus('&q=thuốc'));
        $this->assertSame(['TS-01/L002'], $skus('&q=l002'));
        $this->assertSame(['TS-01/L002'], $skus('&status=uncounted'));
        $this->assertSame(['NPK-16', 'TS-01/L001'], $skus('&status=counted'));
        $this->assertSame(['NPK-16'], $skus('&status=differing'));
        $this->assertSame([], $this->lines($owner, $third), 'kho 2 chưa có tồn');

        $page = $this->api($owner)->getJson("/api/v1/stocktakes/{$second}/lines?per_page=2&page=2")->assertOk();
        $this->assertSame(['L002'], array_map(fn ($l) => $l['batch']['batch_no'], $page->json('data')));
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
    }

    // --- phân quyền và cách ly ----------------------------------------------------------------

    public function test_permissions_follow_the_role_matrix(): void
    {
        [$tenant, $owner, $branch, $kg, , $kho] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $line = $this->lineId($owner, $id, 'NPK-16');
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $accountant = $this->makeUserIn($tenant, ['accountant'], $branch);
        $delivery = $this->makeUserIn($tenant, ['delivery'], $branch);
        $keeper = $this->makeUserIn($tenant, ['warehouse'], $branch);

        // Bán hàng, kế toán, giao hàng không có quyền kiểm kê & điều chỉnh: 403 ở mọi thao tác, kể cả xem.
        foreach ([$sales, $accountant, $delivery] as $user) {
            $this->api($user)->getJson('/api/v1/stocktakes')->assertForbidden();
            $this->api($user)->getJson("/api/v1/stocktakes/{$id}")->assertForbidden();
            $this->api($user)->getJson("/api/v1/stocktakes/{$id}/lines")->assertForbidden();
            $this->api($user)->postJson('/api/v1/stocktakes', [])->assertForbidden();
            $this->countLine($user, $id, $line, 1, $kg->uuid)->assertForbidden();
            $this->api($user)->postJson("/api/v1/stocktakes/{$id}/complete")->assertForbidden();
            $this->api($user)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertForbidden();
        }

        // Thủ kho (quyền chi nhánh: xem + tạo) làm được toàn bộ quy trình ở chi nhánh của mình.
        $this->countLine($keeper, $id, $line, 450, $kg->uuid)->assertOk();
        $this->api($keeper)->getJson('/api/v1/stocktakes')->assertOk()->assertJsonCount(1, 'data');
        $this->api($keeper)->postJson("/api/v1/stocktakes/{$id}/complete")->assertOk();
        $this->start($keeper, $kho)->assertCreated();
    }

    public function test_a_branch_manager_works_only_with_the_stocktakes_of_their_branch_and_tenants_are_isolated(): void
    {
        [$tenant, $owner, $branchA, , , $khoA] = $this->world();
        $branchB = $this->makeBranch($tenant);
        $khoB = $this->warehouseOf($branchB);
        $managerB = $this->makeUserIn($tenant, ['branch_manager'], $branchB);
        $id = $this->start($owner, $khoA)->json('data.id');
        $idB = $this->start($owner, $khoB)->json('data.id');

        $this->api($managerB)->getJson("/api/v1/stocktakes/{$id}")->assertForbidden();
        $this->api($managerB)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertForbidden();
        $this->start($managerB, $khoA)->assertForbidden();
        $this->assertSame([$idB], array_column($this->api($managerB)->getJson('/api/v1/stocktakes')->json('data'), 'id'));
        $this->api($managerB)->postJson("/api/v1/stocktakes/{$idB}/complete")->assertOk();

        // Doanh nghiệp khác không thấy gì, kể cả khi đoán đúng mã công khai.
        [, $ownerC, , , , $khoC] = $this->world();
        $this->api($ownerC)->getJson("/api/v1/stocktakes/{$id}")->assertNotFound();
        $this->api($ownerC)->getJson("/api/v1/stocktakes/{$id}/lines")->assertNotFound();
        $this->api($ownerC)->postJson("/api/v1/stocktakes/{$id}/cancel")->assertNotFound();
        $this->assertSame([], $this->api($ownerC)->getJson('/api/v1/stocktakes')->json('data'));
        $this->start($ownerC, $khoA)->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']); // kho của doanh nghiệp khác
        $this->start($ownerC, $khoC)->assertCreated()->assertJsonPath('data.code', 'KK0001'); // mã đánh số riêng từng doanh nghiệp
    }

    public function test_costs_of_the_difference_are_shown_to_stock_staff(): void
    {
        [$tenant, $owner, $branch, $kg, , $kho] = $this->world();
        $keeper = $this->makeUserIn($tenant, ['warehouse'], $branch);
        $id = $this->start($owner, $kho)->json('data.id');
        $this->countLine($owner, $id, $this->lineId($owner, $id, 'NPK-16'), 490, $kg->uuid)->assertOk();

        foreach ([$owner, $keeper] as $user) {
            $this->api($user)->getJson("/api/v1/stocktakes/{$id}")->assertJsonPath('data.difference_value', '-10000.00');
        }
    }

    // --- ràng buộc ở database và sao lưu ------------------------------------------------------

    public function test_the_database_guards_the_counts_and_the_lines(): void
    {
        [, $owner, , $kg, , $kho] = $this->world();
        $id = $this->start($owner, $kho)->json('data.id');
        $line = $this->platform(fn () => StocktakeLine::orderBy('id')->firstOrFail());
        $unit = $this->platform(fn () => (int) DB::table('units')->where('uuid', $kg->uuid)->value('id'));

        $attempts = [
            'số đếm âm' => fn () => DB::table('stocktake_lines')->where('id', $line->id)->update(['counted_quantity' => -1, 'counted_unit_id' => $unit]),
            'có số đếm mà thiếu đơn vị' => fn () => DB::table('stocktake_lines')->where('id', $line->id)->update(['counted_quantity' => 1]),
            'tồn hệ thống âm' => fn () => DB::table('stocktake_lines')->where('id', $line->id)->update(['system_quantity' => -1]),
            'hai dòng cho cùng sản phẩm và lô' => fn () => DB::table('stocktake_lines')->insert([
                'tenant_id' => $line->tenant_id, 'stocktake_id' => $line->stocktake_id, 'product_id' => $line->product_id, 'batch_id' => $line->batch_id,
                'system_quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'đợt đã đóng mà không có giờ kết thúc' => fn () => DB::table('stocktakes')->where('uuid', $id)->update(['status' => 'cancelled']),
            'trạng thái lạ' => fn () => DB::table('stocktakes')->where('uuid', $id)->update(['status' => 'xyz', 'finished_at' => now()]),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $this->platform($attempt);
                $this->fail("Database không chặn: {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_tables_are_registered_for_backup_with_parents_before_children(): void
    {
        $tables = config('backup.tenant_tables');
        $pos = fn (string $t) => array_search($t, $tables, true);

        foreach (['stocktakes', 'stocktake_lines'] as $table) {
            $this->assertNotFalse($pos($table), "{$table} chưa nằm trong backup.tenant_tables");
            $this->assertTrue(Schema::hasColumns($table, ['id', 'tenant_id']));
        }

        $this->assertLessThan($pos('stocktakes'), $pos('stock_documents'));  // đợt tham chiếu phiếu điều chỉnh
        $this->assertLessThan($pos('stocktakes'), $pos('warehouses'));
        $this->assertLessThan($pos('stocktake_lines'), $pos('stocktakes'));
        $this->assertLessThan($pos('stocktake_lines'), $pos('batches'));
        $this->assertLessThan($pos('stocktake_lines'), $pos('products'));
    }

    public function test_restoring_a_backup_brings_back_the_stocktakes_exactly(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho] = $this->world(['name' => 'Cửa hàng Nông Phát']);
        $kho2 = $this->makeWarehouse($branch);

        $done = $this->start($owner, $kho)->json('data.id');
        $this->countLine($owner, $done, $this->lineId($owner, $done, 'NPK-16'), 8, $bao->uuid)->assertOk();
        $this->api($owner)->postJson("/api/v1/stocktakes/{$done}/complete")->assertOk();
        $open = $this->start($owner, $kho)->json('data.id');
        $this->countLine($owner, $open, $this->lineId($owner, $open, 'TS-01', 'L001'), 9, $kg->uuid)->assertOk();

        $snapshot = fn () => $this->platform(fn () => [
            'stocktakes' => Stocktake::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'uuid', 'warehouse_id', 'code', 'status', 'note', 'started_by', 'finished_by', 'adjustment_document_id'])->toArray(),
            'lines' => StocktakeLine::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'uuid', 'stocktake_id', 'product_id', 'batch_id', 'system_quantity', 'counted_quantity', 'counted_unit_id', 'counted_entered_quantity', 'counted_factor'])->toArray(),
        ]);
        $before = $snapshot();
        $this->assertCount(2, $before['stocktakes']);

        $backup = $this->adminBackup($tenant);
        $this->assertSame(2, $backup->row_counts['stocktakes']);
        $this->assertSame(count($before['lines']), $backup->row_counts['stocktake_lines']);

        // Sau khi sao lưu: hủy đợt đang đếm rồi bắt đầu một đợt khác ở kho 2.
        $this->api($owner)->postJson("/api/v1/stocktakes/{$open}/cancel")->assertOk();
        $this->start($owner, $kho2)->assertCreated();
        $this->assertNotSame($before, $snapshot());

        $this->adminRestore($backup, 'Cửa hàng Nông Phát')->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $snapshot(), 'đợt kiểm kê, dòng đếm, liên kết tới phiếu điều chỉnh khôi phục đúng nguyên trạng');
        // Đợt đang đếm khi sao lưu vẫn đang đếm và kho vẫn bị khóa; kho 2 hết bị khóa.
        $this->api($owner)->getJson("/api/v1/stocktakes/{$open}")->assertJsonPath('data.status', 'counting');
        $this->postStock($owner, $kho2, 'receipt', [['product_id' => $this->platform(fn () => DB::table('products')->where('sku', 'URE-01')->value('uuid')), 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();
    }
}
