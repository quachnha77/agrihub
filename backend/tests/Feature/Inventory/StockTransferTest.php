<?php

namespace Tests\Feature\Inventory;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/**
 * Chuyển kho: MỘT bước (kho gửi trừ, kho nhận cộng ngay), trong cùng chi nhánh và giữa hai chi nhánh. Mỗi lần chuyển ghi một phiếu chuyển và hai phiếu kho đi cặp;
 * sản phẩm bên nhận ghép theo mã (sku); lô giữ số lô + hạn dùng; hàng sang kho nhận theo giá vốn bình quân của kho gửi.
 */
class StockTransferTest extends TestCase
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
     * Cửa hàng có hai chi nhánh A và B. A có hai kho (khoA1 = Kho chính, khoA2), B có một kho. Cùng mã NPK-16 ở cả hai chi nhánh (B viết thường để thử ghép không phân biệt
     * hoa thường) và thuốc TS-01 theo dõi lô ở cả hai. Tồn ở khoA1: NPK-16 200 Kg giá vốn 1.000; TS-01 lô L001 10 Kg (hạn 2030-01-31), L002 5 Kg (hạn 2030-06-30), giá 5.000.
     * Tồn ở khoB: NPK-16 100 Kg giá vốn 2.000.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $w = [
            'tenant' => $tenant, 'owner' => $owner, 'branchA' => $branchA, 'branchB' => $branchB, 'kg' => $kg, 'bao' => $bao,
            'khoA1' => $this->warehouseOf($branchA), 'khoA2' => $this->makeWarehouse($branchA), 'khoB' => $this->warehouseOf($branchB),
            'npkA' => $this->makeProduct($owner, $branchA, $kg, $bao, 'NPK-16'),
            'npkB' => $this->makeProduct($owner, $branchB, $kg, $bao, 'npk-16'),
            'drugA' => $this->makeProduct($owner, $branchA, $kg, $bao, 'TS-01', true),
            'drugB' => $this->makeProduct($owner, $branchB, $kg, $bao, 'TS-01', true),
        ];

        $this->postStock($owner, $w['khoA1'], 'receipt', [
            ['product_id' => $w['npkA']['id'], 'unit_id' => $kg->uuid, 'quantity' => 200, 'unit_cost' => 1000],
            ['product_id' => $w['drugA']['id'], 'unit_id' => $kg->uuid, 'quantity' => 10, 'unit_cost' => 5000, 'batch_no' => 'L001', 'expires_at' => '2030-01-31'],
            ['product_id' => $w['drugA']['id'], 'unit_id' => $kg->uuid, 'quantity' => 5, 'unit_cost' => 5000, 'batch_no' => 'L002', 'expires_at' => '2030-06-30'],
        ])->assertCreated();
        $this->postStock($owner, $w['khoB'], 'receipt', [
            ['product_id' => $w['npkB']['id'], 'unit_id' => $kg->uuid, 'quantity' => 100, 'unit_cost' => 2000],
        ])->assertCreated();

        return $w;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function transfer(object $user, Warehouse $from, Warehouse $to, array $lines, array $extra = []): TestResponse
    {
        return $this->api($user)->postJson('/api/v1/stock/transfers', array_merge(['from_warehouse_id' => $from->uuid, 'to_warehouse_id' => $to->uuid, 'lines' => $lines], $extra));
    }

    private function line(array $product, string $unit, int|float $quantity, array $extra = []): array
    {
        return array_merge(['product_id' => $product['id'], 'unit_id' => $unit, 'quantity' => $quantity], $extra);
    }

    private function productId(array $product): int
    {
        return $this->platform(fn () => Product::where('uuid', $product['id'])->value('id'));
    }

    /** Tồn (đơn vị cơ sở) của sản phẩm trong kho, cộng mọi lô hoặc chỉ một lô. */
    private function stock(Warehouse $kho, array $product, ?string $batchNo = null): float
    {
        $id = $this->productId($product);

        return (float) $this->platform(fn () => StockBalance::where('warehouse_id', $kho->id)->where('product_id', $id)
            ->when($batchNo, fn ($q) => $q->whereIn('batch_id', Batch::where('product_id', $id)->where('batch_no', $batchNo)->select('id')))->sum('quantity'));
    }

    private function avgCost(array $product): string
    {
        $id = $this->productId($product);

        return (string) $this->platform(fn () => ProductCost::where('product_id', $id)->value('avg_cost'));
    }

    private function batchUuid(array $product, string $batchNo): string
    {
        $id = $this->productId($product);

        return $this->platform(fn () => Batch::where('product_id', $id)->where('batch_no', $batchNo)->firstOrFail()->uuid);
    }

    private function nothingWritten(int $transfers = 0): void
    {
        $this->assertSame($transfers, $this->platform(fn () => StockTransfer::count()));
    }

    public function test_a_transfer_between_two_warehouses_of_one_branch_moves_stock_in_one_step(): void
    {
        $w = $this->world();

        $response = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 30)], ['note' => '  Dồn hàng ra kho nhỏ  '])->assertCreated();

        $response->assertJsonPath('data.code', 'CK0001')->assertJsonPath('data.note', 'Dồn hàng ra kho nhỏ')
            ->assertJsonPath('data.from.warehouse.code', 'KHO-01')->assertJsonPath('data.to.warehouse.code', 'KHO-02')
            ->assertJsonPath('data.from.branch.code', $w['branchA']->code)->assertJsonPath('data.to.branch.code', $w['branchA']->code)
            ->assertJsonPath('data.documents.out.code', 'CK0001-X')->assertJsonPath('data.documents.in.code', 'CK0001-N')
            ->assertJsonPath('data.performed_by.name', $w['owner']->name)
            ->assertJsonPath('data.lines.0.product.sku', 'NPK-16')->assertJsonPath('data.lines.0.quantity', '30.000')
            ->assertJsonPath('data.lines.0.unit_cost', '1000.0000')->assertJsonPath('data.value', '30000.00');

        $this->assertSame(170.0, $this->stock($w['khoA1'], $w['npkA']));
        $this->assertSame(30.0, $this->stock($w['khoA2'], $w['npkA']));
        $this->assertSame('1000.0000', $this->avgCost($w['npkA']), 'cùng một sản phẩm: giá vốn không đổi');

        [$out, $in] = $this->platform(fn () => [StockDocument::where('code', 'CK0001-X')->firstOrFail(), StockDocument::where('code', 'CK0001-N')->firstOrFail()]);
        $this->assertSame(StockDocument::TRANSFER_OUT, $out->type);
        $this->assertSame(StockDocument::TRANSFER_IN, $in->type);
        $this->assertSame($out->transfer_id, $in->transfer_id);
        $this->assertSame($w['khoA1']->id, $out->warehouse_id);
        $this->assertSame($w['khoA2']->id, $in->warehouse_id);
        $this->assertSame($w['owner']->id, $out->performed_by);

        [$outLine, $inLine] = $this->platform(fn () => [StockMovement::where('document_id', $out->id)->firstOrFail(), StockMovement::where('document_id', $in->id)->firstOrFail()]);
        $this->assertEquals(-30, $outLine->quantity_delta);
        $this->assertEquals(170, $outLine->balance_after);
        $this->assertEquals(30, $inLine->quantity_delta);
        $this->assertEquals(30, $inLine->balance_after);
        $this->assertEquals(1000, $outLine->unit_cost);
        $this->assertEquals(1000, $inLine->unit_cost);
        $this->assertSame('transfer_out', $outLine->movement_type);
        $this->assertSame('transfer_in', $inLine->movement_type);

        $audit = $this->lastAudit(['action' => 'stock_transfer_created']);
        $this->assertSame('CK0001', $audit->after['code']);
        $this->assertSame($w['branchA']->id, $audit->branch_id);
    }

    public function test_a_transfer_between_branches_matches_products_by_sku_and_reprices_the_receiving_side(): void
    {
        $w = $this->world();

        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 100)])->assertCreated()
            ->assertJsonPath('data.from.branch.code', $w['branchA']->code)->assertJsonPath('data.to.branch.code', $w['branchB']->code)
            ->assertJsonPath('data.value', '100000.00');

        $this->assertSame(100.0, $this->stock($w['khoA1'], $w['npkA']));
        $this->assertSame(200.0, $this->stock($w['khoB'], $w['npkB']), 'sản phẩm npk-16 của chi nhánh B (khác hoa thường) nhận hàng');
        $this->assertSame('1000.0000', $this->avgCost($w['npkA']), 'bên gửi: giá vốn không đổi');
        $this->assertSame('1500.0000', $this->avgCost($w['npkB']), 'bên nhận: (100 × 2.000 + 100 × 1.000) / 200');

        $in = $this->platform(fn () => StockMovement::where('movement_type', 'transfer_in')->firstOrFail());
        $this->assertEquals(1000, $in->unit_cost, 'hàng sang kho nhận theo giá vốn của kho gửi');
        $this->assertSame($this->productId($w['npkB']), $in->product_id);
        $this->assertSame($w['branchB']->id, $in->branch_id, 'phiếu nhập thuộc chi nhánh nhận: thẻ kho của chi nhánh B có dòng này');
    }

    public function test_the_receiving_side_records_the_unit_the_user_chose_when_it_has_it_and_the_base_unit_otherwise(): void
    {
        $w = $this->world();
        $ureA = $this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'URE-01');
        $ureB = $this->spa()->actingAs($w['owner'])->postJson('/api/v1/products', $this->productPayload($w['branchB'], $w['kg'], ['sku' => 'URE-01', 'name' => 'Urê B']))->assertCreated()->json('data');
        $this->postStock($w['owner'], $w['khoA1'], 'receipt', [$this->line($ureA, $w['bao']->uuid, 4, ['unit_cost' => 50000])])->assertCreated();

        // 1 Bao = 50 Kg; B chỉ có Kg → bên nhận ghi 50 Kg. Chuyển trong cùng chi nhánh thì giữ nguyên "Bao".
        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($ureA, $w['bao']->uuid, 1)])->assertCreated();
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($ureA, $w['bao']->uuid, 2)])->assertCreated();

        $this->assertSame(50.0, $this->stock($w['khoB'], $ureB));
        $this->assertSame(100.0, $this->stock($w['khoA2'], $ureA));

        $entered = fn (string $code) => $this->platform(fn () => StockMovement::whereIn('document_id', StockDocument::where('code', $code)->select('id'))->with('enteredUnit')->firstOrFail());
        $toB = $entered('CK0001-N');
        $this->assertSame('Kg', $toB->enteredUnit->name);
        $this->assertEquals(50, $toB->entered_quantity);
        $this->assertEquals(1, $toB->entered_factor);
        $sameBranch = $entered('CK0002-N');
        $this->assertSame('Bao', $sameBranch->enteredUnit->name);
        $this->assertEquals(2, $sameBranch->entered_quantity);
        $this->assertEquals(50, $sameBranch->entered_factor);
    }

    public function test_a_product_missing_in_the_receiving_branch_is_refused_with_a_hint_and_nothing_changes(): void
    {
        $w = $this->world();
        $ureA = $this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'URE-01');
        $this->postStock($w['owner'], $w['khoA1'], 'receipt', [$this->line($ureA, $w['kg']->uuid, 10, ['unit_cost' => 1000])])->assertCreated();

        $response = $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 10), $this->line($ureA, $w['kg']->uuid, 5)])->assertUnprocessable();

        $response->assertJsonValidationErrors(['lines.1.product_id']);
        $this->assertStringContainsString('Sao chép', $response->json('errors')['lines.1.product_id'][0]);
        $this->assertStringContainsString('URE-01', $response->json('errors')['lines.1.product_id'][0]);
        $this->assertArrayNotHasKey('lines.0.product_id', $response->json('errors'));
        $this->assertSame(200.0, $this->stock($w['khoA1'], $w['npkA']));
        $this->assertSame(100.0, $this->stock($w['khoB'], $w['npkB']));
        $this->nothingWritten();
    }

    public function test_products_that_differ_in_base_unit_or_lot_tracking_between_branches_are_refused(): void
    {
        $w = $this->world();
        $this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'DIF-01');
        $difA = $this->platform(fn () => Product::where('sku', 'DIF-01')->firstOrFail());
        $this->spa()->actingAs($w['owner'])->postJson('/api/v1/products', $this->productPayload($w['branchB'], $w['bao'], ['sku' => 'DIF-01', 'name' => 'Khác đơn vị']))->assertCreated();
        $trackA = $this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'TB-01', true);
        $this->spa()->actingAs($w['owner'])->postJson('/api/v1/products', $this->productPayload($w['branchB'], $w['kg'], ['sku' => 'TB-01', 'name' => 'Không lô']))->assertCreated();
        $this->postStock($w['owner'], $w['khoA1'], 'receipt', [
            $this->line(['id' => $difA->uuid], $w['kg']->uuid, 10, ['unit_cost' => 1000]),
            $this->line($trackA, $w['kg']->uuid, 10, ['unit_cost' => 1000, 'batch_no' => 'T1', 'expires_at' => '2030-01-31']),
        ])->assertCreated();

        $response = $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [
            $this->line(['id' => $difA->uuid], $w['kg']->uuid, 1),
            $this->line($trackA, $w['kg']->uuid, 1, ['batch_id' => $this->batchUuid($trackA, 'T1')]),
        ])->assertUnprocessable();

        $this->assertStringContainsString('đơn vị nhỏ nhất khác', $response->json('errors')['lines.0.product_id'][0]);
        $this->assertStringContainsString('theo dõi lô', $response->json('errors')['lines.1.product_id'][0]);
        $this->nothingWritten();
    }

    public function test_insufficient_stock_refuses_the_whole_transfer_and_changes_nothing(): void
    {
        $w = $this->world();

        $response = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 50), $this->line($w['npkA'], $w['kg']->uuid, 151)])->assertUnprocessable();

        $response->assertJsonValidationErrors(['lines.1.quantity']);
        $this->assertStringContainsString('Không đủ tồn', $response->json('errors')['lines.1.quantity'][0]);
        $this->assertSame(200.0, $this->stock($w['khoA1'], $w['npkA']));
        $this->assertSame(0.0, $this->stock($w['khoA2'], $w['npkA']));
        $this->assertSame(0, $this->platform(fn () => StockDocument::whereIn('type', ['transfer_out', 'transfer_in'])->count()));
        $this->nothingWritten();

        // Mã phiếu không bị tiêu hao bởi lần thất bại.
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 200)])->assertCreated()->assertJsonPath('data.code', 'CK0001');
    }

    public function test_the_two_warehouses_must_differ_be_active_and_not_be_locked_by_a_stocktake(): void
    {
        $w = $this->world();
        $line = [$this->line($w['npkA'], $w['kg']->uuid, 10)];

        $this->transfer($w['owner'], $w['khoA1'], $w['khoA1'], $line)->assertUnprocessable()->assertJsonValidationErrors(['to_warehouse_id']);

        $count = $this->api($w['owner'])->postJson('/api/v1/stocktakes', ['warehouse_id' => $w['khoA2']->uuid])->assertCreated()->json('data.id');
        $receiving = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], $line)->assertUnprocessable();
        $this->assertStringContainsString('đang kiểm kê', $receiving->json('errors')['to_warehouse_id'][0]);
        $this->api($w['owner'])->postJson("/api/v1/stocktakes/{$count}/cancel")->assertOk();

        $this->api($w['owner'])->postJson('/api/v1/stocktakes', ['warehouse_id' => $w['khoA1']->uuid])->assertCreated();
        $sending = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], $line)->assertUnprocessable();
        $this->assertStringContainsString('đang kiểm kê', $sending->json('errors')['from_warehouse_id'][0]);

        $this->platform(fn () => Warehouse::whereKey($w['khoB']->id)->update(['status' => 'inactive']));
        $this->transfer($w['owner'], $w['khoA2'], $w['khoB'], $line)->assertUnprocessable()->assertJsonValidationErrors(['to_warehouse_id']);
        $this->nothingWritten();
    }

    public function test_the_lot_travels_with_its_number_and_expiry_and_is_reused_on_the_next_transfer(): void
    {
        $w = $this->world();
        $l001 = $this->batchUuid($w['drugA'], 'L001');

        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['drugA'], $w['kg']->uuid, 6, ['batch_id' => $l001])])->assertCreated()
            ->assertJsonPath('data.lines.0.batch.batch_no', 'L001')->assertJsonPath('data.lines.0.batch.expires_at', '2030-01-31');

        $received = $this->platform(fn () => Batch::where('product_id', $this->productId($w['drugB']))->get());
        $this->assertCount(1, $received);
        $this->assertSame('L001', $received[0]->batch_no);
        $this->assertSame('2030-01-31', $received[0]->expires_at->toDateString());
        $this->assertSame(4.0, $this->stock($w['khoA1'], $w['drugA'], 'L001'));
        $this->assertSame(6.0, $this->stock($w['khoB'], $w['drugB'], 'L001'));
        $this->assertSame(5.0, $this->stock($w['khoA1'], $w['drugA'], 'L002'), 'lô khác không bị đụng tới');

        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['drugA'], $w['kg']->uuid, 4, ['batch_id' => $l001])])->assertCreated();
        $this->assertSame(1, $this->platform(fn () => Batch::where('product_id', $this->productId($w['drugB']))->count()), 'lô bên nhận được dùng lại, không tạo lô trùng');
        $this->assertSame(10.0, $this->stock($w['khoB'], $w['drugB'], 'L001'));

        // Cùng chi nhánh: chính lô đó sang kho kia, không tạo lô mới.
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['drugA'], $w['kg']->uuid, 5, ['batch_id' => $this->batchUuid($w['drugA'], 'L002')])])->assertCreated();
        $this->assertSame(5.0, $this->stock($w['khoA2'], $w['drugA'], 'L002'));
        $this->assertSame(2, $this->platform(fn () => Batch::where('product_id', $this->productId($w['drugA']))->count()));
    }

    public function test_lot_rules_a_lot_is_required_enough_stock_in_it_not_expired_and_a_consistent_expiry_at_the_destination(): void
    {
        $w = $this->world();
        $l001 = $this->batchUuid($w['drugA'], 'L001');

        $missing = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['drugA'], $w['kg']->uuid, 1)])->assertUnprocessable();
        $this->assertStringContainsString('chọn lô', $missing->json('errors')['lines.0.batch_id'][0]);

        $short = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['drugA'], $w['kg']->uuid, 11, ['batch_id' => $l001])])->assertUnprocessable();
        $this->assertStringContainsString('Không đủ tồn của lô L001', $short->json('errors')['lines.0.quantity'][0]);

        // Kho nhận đã có lô cùng số nhưng hạn dùng khác → từ chối (báo ở ô lô của dòng).
        $this->postStock($w['owner'], $w['khoB'], 'receipt', [$this->line($w['drugB'], $w['kg']->uuid, 1, ['unit_cost' => 1, 'batch_no' => 'L001', 'expires_at' => '2031-12-31'])])->assertCreated();
        $clash = $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['drugA'], $w['kg']->uuid, 1, ['batch_id' => $l001])])->assertUnprocessable();
        $this->assertStringContainsString('Kho nhận', $clash->json('errors')['lines.0.batch_id'][0]);
        $this->assertSame(10.0, $this->stock($w['khoA1'], $w['drugA'], 'L001'));

        // Lô quá hạn không chuyển kho được (phải xuất để hủy).
        $this->postStock($w['owner'], $w['khoA1'], 'receipt', [$this->line($w['drugA'], $w['kg']->uuid, 3, ['unit_cost' => 1, 'batch_no' => 'OLD', 'expires_at' => '2020-01-01'])])->assertCreated();
        $expired = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['drugA'], $w['kg']->uuid, 1, ['batch_id' => $this->batchUuid($w['drugA'], 'OLD')])])->assertUnprocessable();
        $this->assertStringContainsString('hết hạn', $expired->json('errors')['lines.0.batch_id'][0]);
        $this->nothingWritten();
    }

    public function test_the_request_shape_is_validated(): void
    {
        $w = $this->world();
        $good = [$this->line($w['npkA'], $w['kg']->uuid, 1)];

        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 0)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, -5)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $this->api($w['owner'])->postJson('/api/v1/stock/transfers', ['lines' => $good])->assertUnprocessable()->assertJsonValidationErrors(['from_warehouse_id', 'to_warehouse_id']);
        $this->api($w['owner'])->postJson('/api/v1/stock/transfers', ['from_warehouse_id' => (string) fake()->uuid(), 'to_warehouse_id' => $w['khoA2']->uuid, 'lines' => $good])->assertUnprocessable()->assertJsonValidationErrors(['from_warehouse_id']);
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkB'], $w['kg']->uuid, 1)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);
        $this->nothingWritten();
    }

    public function test_transfer_documents_cannot_be_posted_directly_and_show_up_in_the_ledger_queries(): void
    {
        $w = $this->world();
        $this->postStock($w['owner'], $w['khoA1'], 'transfer_out', [$this->line($w['npkA'], $w['kg']->uuid, 1)])->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 10)])->assertCreated();

        $documents = $this->api($w['owner'])->getJson('/api/v1/stock/documents?type=transfer_in')->assertOk();
        $this->assertSame(['CK0001-N'], array_column($documents->json('data'), 'code'));
        $this->assertSame('CK0001', $documents->json('data.0.transfer.code'));
        $this->assertSame(['CK0001-X'], array_column($this->api($w['owner'])->getJson('/api/v1/stock/documents?type=transfer_out')->json('data'), 'code'));

        $id = $documents->json('data.0.id');
        $this->api($w['owner'])->getJson("/api/v1/stock/documents/{$id}")->assertOk()->assertJsonPath('data.transfer.code', 'CK0001')->assertJsonPath('data.lines.0.quantity_delta', '10.000');
        $this->assertCount(1, $this->api($w['owner'])->getJson('/api/v1/stock/movements?type=transfer_out')->json('data'));
        $this->assertNull($this->api($w['owner'])->getJson('/api/v1/stock/documents?type=receipt')->json('data.0.transfer'));
    }

    public function test_the_list_and_detail_with_filters(): void
    {
        $w = $this->world();
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 10)])->assertCreated();
        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 20), $this->line($w['drugA'], $w['kg']->uuid, 2, ['batch_id' => $this->batchUuid($w['drugA'], 'L001')])])->assertCreated();

        $list = $this->api($w['owner'])->getJson('/api/v1/stock/transfers')->assertOk();
        $this->assertSame(['CK0002', 'CK0001'], array_column($list->json('data'), 'code'), 'mới nhất trước');
        $this->assertSame([2, 1], array_column($list->json('data'), 'line_count'));
        $this->assertArrayNotHasKey('lines', $list->json('data.0'));

        $this->assertSame(['CK0001'], array_column($this->api($w['owner'])->getJson('/api/v1/stock/transfers?q=0001')->json('data'), 'code'));
        $this->assertSame(['CK0002'], array_column($this->api($w['owner'])->getJson("/api/v1/stock/transfers?warehouse={$w['khoB']->uuid}")->json('data'), 'code'), 'lọc theo kho gửi HOẶC kho nhận');
        $this->assertCount(2, $this->api($w['owner'])->getJson("/api/v1/stock/transfers?warehouse={$w['khoA1']->uuid}")->json('data'));
        $this->assertCount(0, $this->api($w['owner'])->getJson('/api/v1/stock/transfers?from=2999-01-01')->json('data'));
        $this->api($w['owner'])->getJson('/api/v1/stock/transfers?from=hôm-nay')->assertUnprocessable();

        $detail = $this->api($w['owner'])->getJson('/api/v1/stock/transfers/'.$list->json('data.0.id'))->assertOk();
        $this->assertCount(2, $detail->json('data.lines'));
        $this->assertSame(['CK0002-X', 'CK0002-N'], [$detail->json('data.documents.out.code'), $detail->json('data.documents.in.code')]);
        $this->assertSame('20000.00', $detail->json('data.lines.0.value'));
    }

    public function test_warehouse_staff_transfer_inside_their_branch_only_and_other_roles_cannot_transfer(): void
    {
        $w = $this->world();
        $keeper = $this->makeUserIn($w['tenant'], ['warehouse'], $w['branchA']);
        $line = [$this->line($w['npkA'], $w['kg']->uuid, 5)];

        $this->transfer($keeper, $w['khoA1'], $w['khoA2'], $line)->assertCreated();
        $this->transfer($keeper, $w['khoA1'], $w['khoB'], $line)->assertForbidden();
        $this->transfer($keeper, $w['khoB'], $w['khoA1'], [$this->line($w['npkB'], $w['kg']->uuid, 5)])->assertForbidden();

        foreach (['sales', 'accountant', 'delivery'] as $role) {
            $this->transfer($this->makeUserIn($w['tenant'], [$role], $w['branchA']), $w['khoA1'], $w['khoA2'], $line)->assertForbidden();
        }

        $manager = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchA']);
        $this->transfer($manager, $w['khoA1'], $w['khoA2'], $line)->assertCreated();
        $this->transfer($manager, $w['khoA1'], $w['khoB'], $line)->assertForbidden();
        $this->assertSame(2, $this->platform(fn () => StockTransfer::count()));
    }

    public function test_each_branch_sees_the_transfers_that_touch_it_and_only_those(): void
    {
        $w = $this->world();
        $branchC = $this->makeBranch($w['tenant']);
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 5)])->assertCreated();
        $id = $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 5)])->assertCreated()->json('data.id');

        $sender = $this->makeUserIn($w['tenant'], ['warehouse'], $w['branchA']);
        $receiver = $this->makeUserIn($w['tenant'], ['warehouse'], $w['branchB']);
        $outsider = $this->makeUserIn($w['tenant'], ['warehouse'], $branchC);

        $this->assertSame(['CK0002', 'CK0001'], array_column($this->api($sender)->getJson('/api/v1/stock/transfers')->json('data'), 'code'));
        $this->assertSame(['CK0002'], array_column($this->api($receiver)->getJson('/api/v1/stock/transfers')->json('data'), 'code'), 'chi nhánh nhận thấy hàng đến, không thấy việc nội bộ của chi nhánh khác');
        $this->api($receiver)->getJson("/api/v1/stock/transfers/{$id}")->assertOk();
        $this->assertSame([], $this->api($outsider)->getJson('/api/v1/stock/transfers')->json('data'));
        $this->api($outsider)->getJson("/api/v1/stock/transfers/{$id}")->assertForbidden();
    }

    public function test_costs_are_hidden_from_sales_staff(): void
    {
        $w = $this->world();
        $id = $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 5)])->assertCreated()->json('data.id');
        $sales = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $accountant = $this->makeUserIn($w['tenant'], ['accountant'], $w['branchA']);

        $seen = $this->api($sales)->getJson("/api/v1/stock/transfers/{$id}")->assertOk()->json('data');
        $this->assertSame('5.000', $seen['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('value', $seen);
        $this->assertArrayNotHasKey('unit_cost', $seen['lines'][0]);
        $this->assertArrayNotHasKey('value', $seen['lines'][0]);
        $this->assertSame('5000.00', $this->api($accountant)->getJson("/api/v1/stock/transfers/{$id}")->json('data.value'));
    }

    public function test_transfers_are_immutable_and_survive_a_restore_with_their_links(): void
    {
        $w = $this->world();
        $this->transfer($w['owner'], $w['khoA1'], $w['khoB'], [$this->line($w['npkA'], $w['kg']->uuid, 10)])->assertCreated();
        $before = $this->platform(fn () => [
            StockTransfer::orderBy('id')->get(['id', 'uuid', 'code', 'from_warehouse_id', 'to_warehouse_id'])->toArray(),
            StockDocument::whereNotNull('transfer_id')->orderBy('id')->get(['id', 'code', 'type', 'transfer_id'])->toArray(),
        ]);

        $backup = $this->adminBackup($w['tenant']);
        $this->assertSame(1, $backup->row_counts['stock_transfers']);

        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 10)])->assertCreated();
        $this->adminRestore($backup, $w['tenant']->name)->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $this->platform(fn () => [
            StockTransfer::orderBy('id')->get(['id', 'uuid', 'code', 'from_warehouse_id', 'to_warehouse_id'])->toArray(),
            StockDocument::whereNotNull('transfer_id')->orderBy('id')->get(['id', 'code', 'type', 'transfer_id'])->toArray(),
        ]));
        $this->assertSame(190.0, $this->stock($w['khoA1'], $w['npkA']));
        $this->transfer($w['owner'], $w['khoA1'], $w['khoA2'], [$this->line($w['npkA'], $w['kg']->uuid, 1)])->assertCreated()->assertJsonPath('data.code', 'CK0002');

        foreach (['update stock_transfers set note = \'x\'', 'delete from stock_transfers'] as $statement) {
            try {
                $this->platform(fn () => DB::transaction(fn () => DB::statement($statement))); // savepoint: lỗi không làm hỏng transaction của test
                $this->fail("{$statement} phải bị chặn");
            } catch (QueryException $e) {
                $this->assertStringContainsString('chỉ ghi thêm', $e->getMessage());
            }
        }
    }
}
