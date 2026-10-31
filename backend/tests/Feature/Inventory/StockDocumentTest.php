<?php

namespace Tests\Feature\Inventory;

use App\Models\Batch;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Phiếu kho: nhập, xuất, điều chỉnh, tồn đầu kỳ. Tồn không âm, giá vốn bình quân, lô và hạn dùng, sổ kho bất biến. */
class StockDocumentTest extends TestCase
{
    use CreatesTenants, MakesCatalog, MakesStock, ReadsAuditLog, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    /** @return array{0: object, 1: object, 2: object, 3: object, 4: object, 5: object, 6: array<string, mixed>} tenant, owner, branch, kg, bao, kho, sản phẩm thường (Bao = 50 Kg) */
    private function shop(): array
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');

        return [$tenant, $owner, $branch, $kg, $bao, $this->warehouseOf($branch), $this->makeProduct($owner, $branch, $kg, $bao)];
    }

    private function line(array $product, string $unitId, float|int $quantity, array $extra = []): array
    {
        return array_merge(['product_id' => $product['id'], 'unit_id' => $unitId, 'quantity' => $quantity], $extra);
    }

    private function today(int $plusDays = 0): string
    {
        return Carbon::now(config('inventory.timezone'))->addDays($plusDays)->toDateString();
    }

    public function test_every_branch_has_a_main_warehouse_from_the_start(): void
    {
        [, , $branch] = $this->makeTenant();
        $warehouse = $this->warehouseOf($branch);

        $this->assertSame('KHO-01', $warehouse->code);
        $this->assertSame('Kho chính', $warehouse->name);
        $this->assertSame('active', $warehouse->status);
    }

    // --- nhập kho và giá vốn ------------------------------------------------------------------

    public function test_a_receipt_converts_the_unit_to_the_base_unit_and_keeps_the_ledger_and_balance(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho, $product] = $this->shop();

        $response = $this->postStock($owner, $kho, 'receipt', [$this->line($product, $bao->uuid, 10, ['unit_cost' => 750000])], ['note' => 'Nhập lô đầu'])->assertCreated();

        $response->assertJsonPath('data.code', 'PN0001')->assertJsonPath('data.type', 'receipt')->assertJsonPath('data.warehouse.code', 'KHO-01')
            ->assertJsonPath('data.performed_by.id', $owner->uuid)->assertJsonPath('data.note', 'Nhập lô đầu')
            ->assertJsonPath('data.lines.0.quantity_delta', '500.000')   // 10 Bao × 50 = 500 Kg
            ->assertJsonPath('data.lines.0.balance_after', '500.000')
            ->assertJsonPath('data.lines.0.unit_cost', '15000.0000')     // 750.000 đ/Bao = 15.000 đ/Kg
            ->assertJsonPath('data.lines.0.entered.unit', 'Bao')->assertJsonPath('data.lines.0.entered.quantity', '10.000')->assertJsonPath('data.lines.0.entered.factor', '50')
            ->assertJsonPath('data.lines.0.product.base_unit', 'Kg')->assertJsonPath('data.value', '7500000.00');

        $this->assertSame('500.000', $this->platform(fn () => (string) StockBalance::firstOrFail()->quantity));
        $this->assertNotNull($this->lastAudit(['module' => 'inventory', 'action' => 'stock_document_created']));
        $this->assertSame($branch->id, $this->lastAudit(['module' => 'inventory', 'action' => 'stock_document_created'])->branch_id);
    }

    public function test_the_average_cost_is_recomputed_on_receipts_and_issues_leave_it_unchanged(): void
    {
        [, $owner, , $kg, $bao, $kho, $product] = $this->shop();

        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $bao->uuid, 10, ['unit_cost' => 750000])])->assertCreated();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 100, ['unit_cost' => 16000])])->assertCreated();

        // (500 × 15.000 + 100 × 16.000) / 600 = 15.166,6667
        $row = $this->api($owner)->getJson('/api/v1/stock/products')->assertOk()->json('data.0');
        $this->assertSame('600.000', $row['total_qty']);
        $this->assertSame('15166.6667', $row['avg_cost']);

        $issue = $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 50)], ['reason' => 'internal_use'])->assertCreated();
        $issue->assertJsonPath('data.code', 'PX0001')->assertJsonPath('data.lines.0.quantity_delta', '-50.000')->assertJsonPath('data.lines.0.balance_after', '550.000')
            ->assertJsonPath('data.lines.0.unit_cost', '15166.6667');

        $this->assertSame('15166.6667', $this->api($owner)->getJson('/api/v1/stock/products')->json('data.0.avg_cost'));
    }

    public function test_opening_stock_is_a_receipt_marked_as_the_starting_balance(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();

        $this->postStock($owner, $kho, 'opening', [$this->line($product, $kg->uuid, 200, ['unit_cost' => 14000])])->assertCreated()
            ->assertJsonPath('data.code', 'TD0001')->assertJsonPath('data.type', 'opening')->assertJsonPath('data.lines.0.balance_after', '200.000');
        $this->assertSame('14000.0000', $this->api($owner)->getJson('/api/v1/stock/products')->json('data.0.avg_cost'));
    }

    public function test_a_receipt_needs_a_unit_cost_but_an_issue_ignores_one(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();

        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 5)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.unit_cost']);
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 5, ['unit_cost' => 10000])])->assertCreated();
        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 1, ['unit_cost' => 1])], ['reason' => 'sample'])->assertCreated()
            ->assertJsonPath('data.lines.0.unit_cost', '10000.0000');
    }

    // --- không cho tồn âm ---------------------------------------------------------------------

    public function test_stock_can_never_go_negative_and_a_failed_document_changes_nothing(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 30, ['unit_cost' => 10000])])->assertCreated();

        $response = $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 10), $this->line($product, $kg->uuid, 25)], ['reason' => 'damaged'])->assertUnprocessable();
        $response->assertJson(['errors' => ['lines.1.quantity' => ['Không đủ tồn: còn 20 Kg, cần 25 Kg.']]]);

        // Dòng thứ nhất hợp lệ cũng không được ghi: cả phiếu hoặc không gì cả.
        $this->assertSame(1, $this->platform(fn () => StockDocument::count()));
        $this->assertSame(1, $this->platform(fn () => StockMovement::count()));
        $this->assertSame('30.000', $this->platform(fn () => (string) StockBalance::firstOrFail()->quantity));
    }

    public function test_the_same_stock_cannot_be_issued_twice_in_one_document(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();

        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 6), $this->line($product, $kg->uuid, 6)], ['reason' => 'damaged'])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.1.quantity']);
    }

    public function test_the_database_itself_refuses_a_negative_balance(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('stock_balances')->update(['quantity' => -1]));
    }

    // --- điều chỉnh ---------------------------------------------------------------------------

    public function test_an_adjustment_takes_a_signed_quantity_and_a_reason(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 100, ['unit_cost' => 10000])])->assertCreated();

        $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, -3)])->assertUnprocessable()->assertJsonValidationErrors(['reason'])
            ->assertJsonPath('errors.reason.0', 'Vui lòng chọn lý do.');
        $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, -3)], ['reason' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, 0)], ['reason' => 'count_correction'])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);

        $down = $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, -3)], ['reason' => 'count_correction'])->assertCreated();
        $down->assertJsonPath('data.code', 'DC0001')->assertJsonPath('data.lines.0.quantity_delta', '-3.000')->assertJsonPath('data.lines.0.balance_after', '97.000');

        // Điều chỉnh tăng dùng giá vốn hiện tại, không làm đổi giá vốn.
        $up = $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, 2.5)], ['reason' => 'count_correction'])->assertCreated();
        $up->assertJsonPath('data.lines.0.balance_after', '99.500')->assertJsonPath('data.lines.0.unit_cost', '10000.0000');
        $this->assertSame('10000.0000', $this->api($owner)->getJson('/api/v1/stock/products')->json('data.0.avg_cost'));

        // Điều chỉnh giảm cũng không được làm tồn âm.
        $this->postStock($owner, $kho, 'adjustment', [$this->line($product, $kg->uuid, -1000)], ['reason' => 'count_correction'])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    }

    public function test_an_issue_needs_a_reason_from_the_list(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();

        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 1)])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 1)], ['reason' => 'count_correction'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    }

    // --- sản phẩm, đơn vị, kho ----------------------------------------------------------------

    public function test_the_product_must_belong_to_the_branch_of_the_warehouse_and_the_unit_to_the_product(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho, $product] = $this->shop();
        $branch2 = $this->makeBranch($tenant);
        $other = $this->makeProduct($owner, $branch2, $kg, $bao, 'NPK-16');
        $pieces = $this->makeUnit($tenant, 'Cái');

        $this->postStock($owner, $kho, 'receipt', [$this->line($other, $kg->uuid, 1, ['unit_cost' => 1])])->assertUnprocessable()
            ->assertJson(['errors' => ['lines.0.product_id' => ['Sản phẩm không có hoặc không thuộc chi nhánh của kho này.']]]);
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $pieces->uuid, 1, ['unit_cost' => 1])])->assertUnprocessable()
            ->assertJson(['errors' => ['lines.0.unit_id' => ['Đơn vị này không thuộc sản phẩm.']]]);
        $this->assertSame(0, $this->platform(fn () => StockDocument::count()));
    }

    public function test_an_inactive_warehouse_cannot_receive_stock(): void
    {
        [, $owner, $branch, $kg, , $kho, $product] = $this->shop();
        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['status' => 'inactive'])->assertOk();

        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 1, ['unit_cost' => 1])])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_quantities_are_exact_decimals(): void
    {
        [, $owner, , $kg, $bao, $kho, $product] = $this->shop();

        // 0,1 + 0,2 phải đúng 0,3 (số thực sẽ ra 0,30000000000000004).
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 0.1, ['unit_cost' => 1000])])->assertCreated();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 0.2, ['unit_cost' => 1000])])->assertCreated()->assertJsonPath('data.lines.0.balance_after', '0.300');

        // Quá 3 chữ số thập phân thì bị từ chối ở bước kiểm tra dữ liệu.
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 0.0001, ['unit_cost' => 1000])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    }

    // --- lô và hạn dùng -----------------------------------------------------------------------

    public function test_a_product_that_tracks_batches_needs_the_batch_number_and_expiry_on_receipt(): void
    {
        [$tenant, $owner, $branch, $kg, $bao, $kho] = $this->shop();
        $drug = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-01', true);
        $this->assertTrue($drug['track_batch']);

        $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 10, ['unit_cost' => 5000])])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.batch_no', 'lines.0.expires_at']);

        $ok = $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 10, ['unit_cost' => 5000, 'batch_no' => 'L001', 'expires_at' => $this->today(365), 'manufactured_at' => $this->today(-30)])])
            ->assertCreated();
        $ok->assertJsonPath('data.lines.0.batch.batch_no', 'L001')->assertJsonPath('data.lines.0.batch.expires_at', $this->today(365));

        // Cùng số lô (không phân biệt hoa/thường) và cùng hạn dùng thì cộng vào lô cũ; khác hạn dùng thì từ chối.
        $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 5, ['unit_cost' => 5000, 'batch_no' => 'l001', 'expires_at' => $this->today(365)])])->assertCreated()
            ->assertJsonPath('data.lines.0.balance_after', '15.000');
        $this->assertSame(1, $this->platform(fn () => Batch::count()));

        $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 5, ['unit_cost' => 5000, 'batch_no' => 'L001', 'expires_at' => $this->today(400)])])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.expires_at']);
        $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 5, ['unit_cost' => 5000, 'batch_no' => 'L002', 'expires_at' => $this->today(10), 'manufactured_at' => $this->today(20)])])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.manufactured_at']);
        $this->assertSame(1, $this->platform(fn () => Batch::count()), 'phiếu lỗi không để lại lô mồ côi');
    }

    public function test_an_issue_picks_the_batch_and_a_product_without_batches_refuses_one(): void
    {
        [, $owner, $branch, $kg, $bao, $kho, $plain] = $this->shop();
        $drug = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-01', true);
        $first = $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 10, ['unit_cost' => 5000, 'batch_no' => 'L001', 'expires_at' => $this->today(100)])])->assertCreated()->json('data.lines.0.batch.id');
        $second = $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 8, ['unit_cost' => 5000, 'batch_no' => 'L002', 'expires_at' => $this->today(200)])])->assertCreated()->json('data.lines.0.batch.id');

        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 1)], ['reason' => 'sample'])->assertUnprocessable()->assertJson(['errors' => ['lines.0.batch_id' => ['Vui lòng chọn lô cần xuất.']]]);

        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 4, ['batch_id' => $second])], ['reason' => 'sample'])->assertCreated()
            ->assertJsonPath('data.lines.0.balance_after', '4.000')->assertJsonPath('data.lines.0.batch.batch_no', 'L002');
        // Số dư tính riêng từng lô: lô L001 vẫn đủ 10.
        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 5, ['batch_id' => $second])], ['reason' => 'sample'])->assertUnprocessable()
            ->assertJson(['errors' => ['lines.0.quantity' => ['Không đủ tồn của lô L002: còn 4 Kg, cần 5 Kg.']]]);
        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 5, ['batch_id' => $first])], ['reason' => 'sample'])->assertCreated();

        $this->postStock($owner, $kho, 'receipt', [$this->line($plain, $kg->uuid, 5, ['unit_cost' => 1000, 'batch_no' => 'X'])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.batch_id']);
    }

    public function test_an_expired_batch_can_only_be_issued_to_destroy_it(): void
    {
        [, $owner, $branch, $kg, $bao, $kho] = $this->shop();
        $drug = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-01', true);
        $old = $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 10, ['unit_cost' => 5000, 'batch_no' => 'CU', 'expires_at' => $this->today(-3)])])->assertCreated()->json('data.lines.0.batch.id');

        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 2, ['batch_id' => $old])], ['reason' => 'internal_use'])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.batch_id']);
        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 2, ['batch_id' => $old])], ['reason' => 'expired'])->assertCreated();
        $this->postStock($owner, $kho, 'adjustment', [$this->line($drug, $kg->uuid, -1, ['batch_id' => $old])], ['reason' => 'expired'])->assertCreated();

        // Hạn dùng là ngày cuối còn dùng được: lô hết hạn HÔM NAY vẫn xuất được.
        $today = $this->postStock($owner, $kho, 'receipt', [$this->line($drug, $kg->uuid, 3, ['unit_cost' => 5000, 'batch_no' => 'HOMNAY', 'expires_at' => $this->today()])])->assertCreated()->json('data.lines.0.batch.id');
        $this->postStock($owner, $kho, 'issue', [$this->line($drug, $kg->uuid, 1, ['batch_id' => $today])], ['reason' => 'internal_use'])->assertCreated();
    }

    public function test_a_batch_of_another_product_cannot_be_used(): void
    {
        [, $owner, $branch, $kg, $bao, $kho] = $this->shop();
        $a = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-A', true);
        $b = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-B', true);
        $batchOfA = $this->postStock($owner, $kho, 'receipt', [$this->line($a, $kg->uuid, 10, ['unit_cost' => 5000, 'batch_no' => 'L1', 'expires_at' => $this->today(100)])])->assertCreated()->json('data.lines.0.batch.id');
        $this->postStock($owner, $kho, 'receipt', [$this->line($b, $kg->uuid, 10, ['unit_cost' => 5000, 'batch_no' => 'L1', 'expires_at' => $this->today(100)])])->assertCreated();

        $this->postStock($owner, $kho, 'issue', [$this->line($b, $kg->uuid, 1, ['batch_id' => $batchOfA])], ['reason' => 'sample'])->assertUnprocessable()
            ->assertJson(['errors' => ['lines.0.batch_id' => ['Lô không có hoặc không thuộc sản phẩm này.']]]);
    }

    // --- nhiều kho ----------------------------------------------------------------------------

    public function test_balances_are_kept_per_warehouse_and_the_product_total_adds_them_up(): void
    {
        [, $owner, $branch, $kg, , $kho, $product] = $this->shop();
        $kho2 = $this->makeWarehouse($branch);

        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 100, ['unit_cost' => 10000])])->assertCreated();
        $this->postStock($owner, $kho2, 'receipt', [$this->line($product, $kg->uuid, 50, ['unit_cost' => 16000])])->assertCreated();

        $this->assertSame('150.000', $this->api($owner)->getJson('/api/v1/stock/products')->json('data.0.total_qty'));
        $this->assertSame('100.000', $this->api($owner)->getJson("/api/v1/stock/products?warehouse={$kho->uuid}")->json('data.0.total_qty'));
        $this->assertSame('50.000', $this->api($owner)->getJson("/api/v1/stock/products?warehouse={$kho2->uuid}")->json('data.0.total_qty'));
        // Giá vốn bình quân tính cho sản phẩm ở cả chi nhánh: (100 × 10.000 + 50 × 16.000) / 150 = 12.000.
        $this->assertSame('12000.0000', $this->api($owner)->getJson('/api/v1/stock/products')->json('data.0.avg_cost'));

        // Xuất ở kho nào chỉ trừ tồn của kho đó.
        $this->postStock($owner, $kho2, 'issue', [$this->line($product, $kg->uuid, 60)], ['reason' => 'damaged'])->assertUnprocessable();
    }

    // --- sổ kho bất biến ----------------------------------------------------------------------

    public function test_the_ledger_cannot_be_edited_or_deleted_even_by_raw_sql(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();

        foreach ([
            fn () => DB::table('stock_movements')->update(['quantity_delta' => 999]),
            fn () => DB::table('stock_movements')->delete(),
            fn () => DB::table('stock_documents')->update(['note' => 'sửa lén']),
            fn () => DB::table('stock_documents')->delete(),
        ] as $attempt) {
            try {
                $this->platform(fn () => DB::transaction($attempt));
                $this->fail('Sổ kho phải chặn sửa/xóa.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame('10.000', $this->platform(fn () => (string) StockMovement::firstOrFail()->quantity_delta));
    }

    public function test_documents_and_movements_cannot_be_changed_through_the_api(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $id = $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated()->json('data.id');

        $this->api($owner)->patchJson("/api/v1/stock/documents/{$id}", ['note' => 'x'])->assertStatus(405);
        $this->api($owner)->deleteJson("/api/v1/stock/documents/{$id}")->assertStatus(405);
    }

    // --- quyền, cô lập ------------------------------------------------------------------------

    public function test_permissions_follow_the_role_matrix(): void
    {
        [$tenant, $owner, $branch, $kg, , $kho, $product] = $this->shop();
        $line = [$this->line($product, $kg->uuid, 5, ['unit_cost' => 1000])];
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $accountant = $this->makeUserIn($tenant, ['accountant'], $branch);
        $keeper = $this->makeUserIn($tenant, ['warehouse'], $branch);
        $delivery = $this->makeUserIn($tenant, ['delivery'], $branch);

        // 403 đến trước kiểm tra dữ liệu: người không có quyền không nhận được lỗi của form.
        foreach ([$sales, $accountant, $delivery] as $user) {
            $this->api($user)->postJson('/api/v1/stock/documents', [])->assertForbidden();
            $this->postStock($user, $kho, 'receipt', $line)->assertForbidden();
        }

        $this->postStock($keeper, $kho, 'receipt', $line)->assertCreated();
        $this->postStock($keeper, $kho, 'adjustment', [$this->line($product, $kg->uuid, -1)], ['reason' => 'count_correction'])->assertCreated();

        // Xem: bán hàng và kế toán xem được; giao hàng không có quyền xem kho.
        $this->api($sales)->getJson('/api/v1/stock/documents')->assertOk();
        $this->api($accountant)->getJson('/api/v1/stock/products')->assertOk();
        $this->api($delivery)->getJson('/api/v1/stock/products')->assertForbidden();
        $this->api($delivery)->getJson('/api/v1/stock/documents')->assertForbidden();
        $this->api($delivery)->getJson('/api/v1/stock/movements')->assertForbidden();
    }

    public function test_a_branch_manager_works_only_with_the_warehouses_of_their_branch(): void
    {
        [$tenant, $owner, $branchA, $kg, $bao, $khoA, $productA] = $this->shop();
        $branchB = $this->makeBranch($tenant);
        $khoB = $this->warehouseOf($branchB);
        $productB = $this->makeProduct($owner, $branchB, $kg, $bao, 'NPK-16');
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branchA);

        $this->postStock($owner, $khoB, 'receipt', [$this->line($productB, $kg->uuid, 5, ['unit_cost' => 1000])])->assertCreated();
        $idA = $this->postStock($manager, $khoA, 'receipt', [$this->line($productA, $kg->uuid, 5, ['unit_cost' => 1000])])->assertCreated()->json('data.id');
        $idB = $this->platform(fn () => StockDocument::where('branch_id', $branchB->id)->firstOrFail()->uuid);

        $this->postStock($manager, $khoB, 'receipt', [$this->line($productB, $kg->uuid, 5, ['unit_cost' => 1000])])->assertForbidden();
        $this->api($manager)->getJson("/api/v1/stock/documents/{$idB}")->assertForbidden();
        $this->api($manager)->getJson("/api/v1/stock/documents/{$idA}")->assertOk();
        $this->assertSame([$idA], array_column($this->api($manager)->getJson('/api/v1/stock/documents')->json('data'), 'id'));
        $this->assertSame(['NPK-16'], array_column($this->api($manager)->getJson('/api/v1/stock/products')->json('data'), 'sku'));
        $this->assertCount(1, $this->api($manager)->getJson('/api/v1/stock/movements')->json('data'));
    }

    public function test_costs_are_visible_to_stock_staff_and_accountants_but_not_to_sales(): void
    {
        [$tenant, $owner, $branch, $kg, , $kho, $product] = $this->shop();
        $id = $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 12000])])->assertCreated()->json('data.id');
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $accountant = $this->makeUserIn($tenant, ['accountant'], $branch);
        $keeper = $this->makeUserIn($tenant, ['warehouse'], $branch);

        foreach ([$owner, $accountant, $keeper] as $user) {
            $this->assertSame('12000.0000', $this->api($user)->getJson('/api/v1/stock/products')->json('data.0.avg_cost'));
            $this->assertSame('12000.0000', $this->api($user)->getJson("/api/v1/stock/documents/{$id}")->json('data.lines.0.unit_cost'));
            $this->assertArrayHasKey('stock_value', $this->api($user)->getJson('/api/v1/stock/summary')->json('data'));
        }

        // Nhân viên bán hàng thấy số lượng nhưng KHÔNG thấy giá vốn, ở mọi nơi.
        $products = $this->api($sales)->getJson('/api/v1/stock/products')->assertOk()->json('data.0');
        $this->assertSame('10.000', $products['total_qty']);
        $this->assertArrayNotHasKey('avg_cost', $products);
        $this->assertArrayNotHasKey('value', $products);
        $document = $this->api($sales)->getJson("/api/v1/stock/documents/{$id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('value', $document);
        $this->assertArrayNotHasKey('unit_cost', $document['lines'][0]);
        $this->assertArrayNotHasKey('unit_cost', $this->api($sales)->getJson('/api/v1/stock/movements')->json('data.0'));
        $this->assertArrayNotHasKey('stock_value', $this->api($sales)->getJson('/api/v1/stock/summary')->json('data'));
    }

    public function test_one_business_never_sees_or_touches_anothers_stock(): void
    {
        [, $ownerA, , $kg, , $khoA, $productA] = $this->shop();
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $kgB = $this->makeUnit($tenantB, 'Kg');
        $baoB = $this->makeUnit($tenantB, 'Bao');
        $khoB = $this->warehouseOf($branchB);
        $productB = $this->makeProduct($ownerB, $branchB, $kgB, $baoB);

        $idB = $this->postStock($ownerB, $khoB, 'receipt', [$this->line($productB, $kgB->uuid, 7, ['unit_cost' => 1000])])->assertCreated()->json('data.id');
        $this->postStock($ownerA, $khoA, 'receipt', [$this->line($productA, $kg->uuid, 3, ['unit_cost' => 1000])])->assertCreated();

        $this->api($ownerA)->getJson("/api/v1/stock/documents/{$idB}")->assertNotFound();
        $this->assertCount(1, $this->api($ownerA)->getJson('/api/v1/stock/documents')->json('data'));
        $this->assertSame('3.000', $this->api($ownerA)->getJson('/api/v1/stock/products')->json('data.0.total_qty'));

        // Ghi vào kho / dùng sản phẩm của doanh nghiệp khác: kho không "tồn tại" với A, sản phẩm của B không thuộc kho của A.
        $this->postStock($ownerA, $khoB, 'receipt', [$this->line($productB, $kgB->uuid, 1, ['unit_cost' => 1])])->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
        $this->postStock($ownerA, $khoA, 'receipt', [$this->line($productB, $kg->uuid, 1, ['unit_cost' => 1])])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);
        $this->assertSame('7.000', $this->platform(fn () => (string) StockBalance::where('tenant_id', $tenantB->id)->firstOrFail()->quantity));
    }

    public function test_row_level_security_hides_other_tenants_stock_even_from_raw_queries(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();
        [$tenantB] = $this->makeTenant();

        $this->app->make(TenantContext::class)->runForTenant($tenantB->id, function () use ($tenantB) {
            foreach (['stock_documents', 'stock_movements', 'stock_balances', 'batches', 'product_costs', 'warehouses'] as $table) {
                $this->assertSame(0, DB::table($table)->where('tenant_id', '!=', $tenantB->id)->count(), "{$table} lộ dữ liệu của doanh nghiệp khác");
            }
        });
    }

    public function test_the_document_list_filters_and_ids_that_are_not_uuids_are_404(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 10, ['unit_cost' => 1000])])->assertCreated();
        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 2)], ['reason' => 'damaged'])->assertCreated();

        $this->assertSame(['PX0001', 'PN0001'], array_column($this->api($owner)->getJson('/api/v1/stock/documents')->json('data'), 'code'));
        $this->assertSame(['PN0001'], array_column($this->api($owner)->getJson('/api/v1/stock/documents?type=receipt')->json('data'), 'code'));
        $this->assertSame(['PX0001'], array_column($this->api($owner)->getJson('/api/v1/stock/documents?q=px')->json('data'), 'code'));
        $this->assertSame(1, $this->api($owner)->getJson("/api/v1/stock/documents?warehouse={$kho->uuid}&from={$this->today()}&to={$this->today()}&type=issue")->json('meta.total'));
        $this->assertSame(0, $this->api($owner)->getJson('/api/v1/stock/documents?to='.$this->today(-1))->json('meta.total'));
        $this->api($owner)->getJson('/api/v1/stock/documents/khong-phai-uuid')->assertNotFound();
        $this->api($owner)->getJson('/api/v1/stock/documents?type=bậy')->assertUnprocessable();
    }

    public function test_codes_are_numbered_per_business_and_type(): void
    {
        [, $owner, , $kg, , $kho, $product] = $this->shop();
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $khoB = $this->warehouseOf($branchB);
        $productB = $this->makeProduct($ownerB, $branchB, $this->makeUnit($tenantB, 'Kg'), $this->makeUnit($tenantB, 'Bao'));

        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 1, ['unit_cost' => 1])])->assertJsonPath('data.code', 'PN0001');
        $this->postStock($owner, $kho, 'receipt', [$this->line($product, $kg->uuid, 1, ['unit_cost' => 1])])->assertJsonPath('data.code', 'PN0002');
        $this->postStock($owner, $kho, 'issue', [$this->line($product, $kg->uuid, 1)], ['reason' => 'damaged'])->assertJsonPath('data.code', 'PX0001');
        $unitB = $this->platform(fn () => Unit::where('tenant_id', $tenantB->id)->where('name', 'Kg')->firstOrFail());
        $this->postStock($ownerB, $khoB, 'receipt', [$this->line($productB, $unitB->uuid, 1, ['unit_cost' => 1])])->assertJsonPath('data.code', 'PN0001');
    }
}
