<?php

namespace Tests\Feature\Purchase;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\StockBalance;
use App\Models\StockDocument;
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
 * Mua hàng: phiếu nhập hàng (hàng vào kho ngay qua StockService, không cần duyệt), chi phí kèm theo tính vào giá vốn phân bổ theo giá trị, ghi nợ nhà cung cấp và trả tiền
 * (một phần, nhiều lần, hủy lần trả nhầm). Chủ sản phẩm chốt 2026-11-01: chỉ Phiếu nhập hàng, không duyệt, trả tiền ngay tại phiếu, chi phí kèm theo vào giá vốn.
 */
class PurchaseTest extends TestCase
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
     * Cửa hàng có hai chi nhánh A (kho khoA) và B (kho khoB), một nhà cung cấp dùng chung, phân NPK-16 (Kg, Bao = 50 Kg) và thuốc TS-01 (theo dõi lô) ở chi nhánh A.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');

        return [
            'tenant' => $tenant, 'owner' => $owner, 'branchA' => $branchA, 'branchB' => $branchB, 'kg' => $kg, 'bao' => $bao,
            'khoA' => $this->warehouseOf($branchA), 'khoB' => $this->warehouseOf($branchB),
            'supplier' => $this->api($owner)->postJson('/api/v1/suppliers', ['name' => 'Công ty Phân bón Bình Điền'])->assertCreated()->json('data'),
            'npk' => $this->makeProduct($owner, $branchA, $kg, $bao, 'NPK-16'),
            'drug' => $this->makeProduct($owner, $branchA, $kg, $bao, 'TS-01', true),
        ];
    }

    private function line(array $product, string $unit, int|float $quantity, int|float $cost, array $extra = []): array
    {
        return array_merge(['product_id' => $product['id'], 'unit_id' => $unit, 'quantity' => $quantity, 'unit_cost' => $cost], $extra);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function receive(array $w, array $lines, array $extra = [], ?object $user = null, ?Warehouse $kho = null): TestResponse
    {
        return $this->api($user ?? $w['owner'])->postJson('/api/v1/purchases', array_merge(['warehouse_id' => ($kho ?? $w['khoA'])->uuid, 'supplier_id' => $w['supplier']['id'], 'lines' => $lines], $extra));
    }

    private function productId(array $product): int
    {
        return $this->platform(fn () => Product::where('uuid', $product['id'])->value('id'));
    }

    private function stock(Warehouse $kho, array $product): float
    {
        $id = $this->productId($product);

        return (float) $this->platform(fn () => StockBalance::where('warehouse_id', $kho->id)->where('product_id', $id)->sum('quantity'));
    }

    private function avgCost(array $product): string
    {
        $id = $this->productId($product);

        return (string) $this->platform(fn () => ProductCost::where('product_id', $id)->value('avg_cost'));
    }

    private function nothingWritten(): void
    {
        $this->assertSame(0, $this->platform(fn () => PurchaseReceipt::count()));
        $this->assertSame(0, $this->platform(fn () => StockDocument::count()));
    }

    public function test_receiving_goods_puts_them_in_stock_at_once_and_records_the_debt_and_the_first_payment(): void
    {
        $w = $this->world();

        $response = $this->receive($w, [
            $this->line($w['npk'], $w['bao']->uuid, 4, 700000),
            $this->line($w['drug'], $w['kg']->uuid, 10, 50000, ['batch_no' => 'L001', 'expires_at' => '2030-01-31']),
        ], ['supplier_ref' => ' HD-0042 ', 'note' => 'Giao tận kho', 'received_on' => '2026-10-30', 'paid_amount' => 1000000, 'payment_method' => 'bank_transfer'])->assertCreated();

        $response->assertJsonPath('data.code', 'NH0001')->assertJsonPath('data.supplier_ref', 'HD-0042')->assertJsonPath('data.received_on', '2026-10-30')
            ->assertJsonPath('data.supplier.name', 'Công ty Phân bón Bình Điền')->assertJsonPath('data.warehouse.code', 'KHO-01')
            ->assertJsonPath('data.goods_total', '3300000.00')->assertJsonPath('data.extra_cost', '0.00')->assertJsonPath('data.paid_amount', '1000000.00')
            ->assertJsonPath('data.balance', '2300000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.stock_document.code', 'PN0001')->assertJsonPath('data.created_by.name', $w['owner']->name)
            ->assertJsonPath('data.lines.0.product.sku', 'NPK-16')->assertJsonPath('data.lines.0.unit', 'Bao')->assertJsonPath('data.lines.0.quantity', '4.000')
            ->assertJsonPath('data.lines.0.unit_cost', '700000.0000')->assertJsonPath('data.lines.0.line_total', '2800000.00')->assertJsonPath('data.lines.0.cost', '700000.0000')
            ->assertJsonPath('data.lines.1.batch.batch_no', 'L001')->assertJsonPath('data.lines.1.batch.expires_at', '2030-01-31')
            ->assertJsonPath('data.payments.0.amount', '1000000.00')->assertJsonPath('data.payments.0.method', 'bank_transfer')->assertJsonPath('data.payments.0.paid_on', '2026-10-30');

        // Hàng đã vào kho (4 Bao = 200 Kg) với giá vốn 700.000 / 50 = 14.000 mỗi Kg; thuốc vào đúng lô.
        $this->assertSame(200.0, $this->stock($w['khoA'], $w['npk']));
        $this->assertSame(10.0, $this->stock($w['khoA'], $w['drug']));
        $this->assertSame('14000.0000', $this->avgCost($w['npk']));
        $this->assertSame('50000.0000', $this->avgCost($w['drug']));
        $this->assertSame(1, $this->platform(fn () => Batch::where('batch_no', 'L001')->count()));

        $document = $this->platform(fn () => StockDocument::where('code', 'PN0001')->firstOrFail());
        $this->assertSame('receipt', $document->type);
        $this->assertSame($w['khoA']->id, $document->warehouse_id);
        $this->assertStringContainsString('NH0001', (string) $document->note);

        $audit = $this->lastAudit(['module' => 'purchase', 'action' => 'receipt_created']);
        $this->assertSame('NH0001', $audit->after['code']);
        $this->assertSame($w['branchA']->id, $audit->branch_id);
    }

    public function test_extra_costs_are_spread_by_value_into_the_cost_but_not_into_the_debt(): void
    {
        $w = $this->world();

        $response = $this->receive($w, [
            $this->line($w['npk'], $w['kg']->uuid, 100, 6000),
            $this->line($w['drug'], $w['kg']->uuid, 40, 10000, ['batch_no' => 'L001', 'expires_at' => '2030-01-31']),
        ], ['extra_cost' => 100000])->assertCreated();

        $response->assertJsonPath('data.goods_total', '1000000.00')->assertJsonPath('data.extra_cost', '100000.00')->assertJsonPath('data.balance', '1000000.00')
            ->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.lines.0.extra_allocated', '60000.00')->assertJsonPath('data.lines.1.extra_allocated', '40000.00')
            ->assertJsonPath('data.lines.0.cost', '6600.0000')->assertJsonPath('data.lines.1.cost', '11000.0000')->assertJsonPath('data.payments', []);

        $this->assertSame('6600.0000', $this->avgCost($w['npk']), '(600.000 + 60.000) / 100 Kg');
        $this->assertSame('11000.0000', $this->avgCost($w['drug']), '(400.000 + 40.000) / 40 Kg');
    }

    public function test_the_extra_cost_is_allocated_to_the_cent_even_when_it_does_not_divide_evenly(): void
    {
        $w = $this->world();
        $ure = $this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'URE-01');

        $lines = $this->receive($w, [
            $this->line($w['npk'], $w['kg']->uuid, 1, 1000), $this->line($ure, $w['kg']->uuid, 1, 1000),
            $this->line($this->makeProduct($w['owner'], $w['branchA'], $w['kg'], $w['bao'], 'KCL-01'), $w['kg']->uuid, 1, 1000),
        ], ['extra_cost' => 100])->assertCreated()->json('data.lines');

        $shares = array_map(fn ($line) => $line['extra_allocated'], $lines);
        $this->assertSame('100.00', number_format(array_sum(array_map('floatval', $shares)), 2, '.', ''), 'tổng phân bổ luôn đúng bằng chi phí');
        $this->assertEqualsCanonicalizing(['33.34', '33.33', '33.33'], $shares);
    }

    public function test_extra_cost_cannot_be_spread_over_free_goods_and_payment_cannot_exceed_the_goods_total(): void
    {
        $w = $this->world();

        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 0)], ['extra_cost' => 5000])->assertUnprocessable()->assertJsonValidationErrors(['extra_cost']);
        $over = $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['paid_amount' => 10000.01])->assertUnprocessable();
        $over->assertJsonValidationErrors(['paid_amount']);
        $this->assertStringContainsString('không được lớn hơn tổng tiền hàng', $over->json('errors.paid_amount.0'));
        $this->nothingWritten();

        // Trả đúng bằng tiền hàng thì được và phiếu là "đã trả đủ".
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['paid_amount' => 10000])->assertCreated()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.code', 'NH0001');
    }

    public function test_a_failure_in_the_stock_step_rolls_back_the_whole_receipt(): void
    {
        $w = $this->world();

        // Thuốc theo dõi lô mà không khai số lô: lỗi báo ở đúng dòng và ô, không phiếu nào được ghi.
        $missing = $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000), $this->line($w['drug'], $w['kg']->uuid, 5, 5000)], ['paid_amount' => 100])->assertUnprocessable();
        $missing->assertJsonValidationErrors(['lines.1.batch_no', 'lines.1.expires_at']);
        $this->nothingWritten();
        $this->assertSame(0.0, $this->stock($w['khoA'], $w['npk']));

        // Kho đang kiểm kê bị khóa.
        $count = $this->api($w['owner'])->postJson('/api/v1/stocktakes', ['warehouse_id' => $w['khoA']->uuid])->assertCreated()->json('data.id');
        $locked = $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)])->assertUnprocessable();
        $this->assertStringContainsString('đang kiểm kê', $locked->json('errors.warehouse_id.0'));
        $this->api($w['owner'])->postJson("/api/v1/stocktakes/{$count}/cancel")->assertOk();
        $this->nothingWritten();

        // Sản phẩm của chi nhánh khác kho.
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], [], null, $w['khoB'])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);
        $this->nothingWritten();
    }

    public function test_the_request_shape_the_supplier_and_the_date_are_validated(): void
    {
        $w = $this->world();
        $good = [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)];

        $this->receive($w, [])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 0, 1000)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 1, -1)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.unit_cost']);
        $this->receive($w, $good, ['received_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['received_on']);
        $this->receive($w, $good, ['paid_amount' => -1])->assertUnprocessable()->assertJsonValidationErrors(['paid_amount']);
        $this->receive($w, $good, ['payment_method' => 'bitcoin', 'paid_amount' => 10])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);
        $this->api($w['owner'])->postJson('/api/v1/purchases', ['warehouse_id' => $w['khoA']->uuid, 'supplier_id' => (string) fake()->uuid(), 'lines' => $good])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

        // Nhà cung cấp ngừng giao dịch thì không nhập hàng được.
        $this->api($w['owner'])->patchJson('/api/v1/suppliers/'.$w['supplier']['id'], ['status' => 'inactive'])->assertOk();
        $this->receive($w, $good)->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
        $this->nothingWritten();
    }

    public function test_payments_can_be_made_in_several_steps_never_beyond_the_debt_and_a_wrong_one_can_be_voided(): void
    {
        $w = $this->world();
        $id = $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 100000)], ['paid_amount' => 200000])->assertCreated()->json('data.id');
        $pay = fn (array $body) => $this->api($w['owner'])->postJson("/api/v1/purchases/{$id}/payments", $body);

        $pay(['amount' => 300000, 'method' => 'cash', 'note' => 'Trả đợt 2', 'paid_on' => '2026-10-31'])->assertOk()
            ->assertJsonPath('data.paid_amount', '500000.00')->assertJsonPath('data.balance', '500000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.1.note', 'Trả đợt 2')->assertJsonPath('data.payments.1.created_by.name', $w['owner']->name);

        $over = $pay(['amount' => 500000.01, 'method' => 'cash'])->assertUnprocessable();
        $over->assertJsonValidationErrors(['amount']);
        $this->assertStringContainsString('vượt quá số còn nợ', $over->json('errors.amount.0'));
        $pay(['amount' => 0, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $pay(['amount' => 100, 'method' => 'momo'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $pay(['amount' => 100, 'method' => 'cash', 'paid_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['paid_on']);

        $pay(['amount' => 500000, 'method' => 'bank_transfer'])->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.balance', '0.00');
        $pay(['amount' => 1, 'method' => 'cash'])->assertUnprocessable();

        // Trả nhầm: hủy lần thứ hai thì nợ quay lại, lần đã hủy vẫn còn trong lịch sử kèm lý do.
        $second = $this->api($w['owner'])->getJson("/api/v1/purchases/{$id}")->json('data.payments.1.id');
        $void = fn (string $payment, array $body) => $this->api($w['owner'])->postJson("/api/v1/purchases/{$id}/payments/{$payment}/void", $body);
        $void($second, [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $void($second, ['reason' => 'Ghi nhầm số tiền'])->assertOk()->assertJsonPath('data.paid_amount', '700000.00')->assertJsonPath('data.balance', '300000.00')
            ->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.payments.1.voided.reason', 'Ghi nhầm số tiền')->assertJsonPath('data.payments.1.voided.by.name', $w['owner']->name)
            ->assertJsonPath('data.payments.0.voided', null);
        $void($second, ['reason' => 'Lần nữa'])->assertUnprocessable()->assertJsonValidationErrors(['payment']);
        $this->api($w['owner'])->postJson("/api/v1/purchases/{$id}/payments/".fake()->uuid().'/void', ['reason' => 'Không có'])->assertNotFound();

        // Có thể trả lại số đã hủy.
        $pay(['amount' => 300000, 'method' => 'cash'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(4, $this->platform(fn () => PurchasePayment::count()), 'không có lần trả nào bị xóa');

        $this->assertSame(['payment_recorded', 'payment_voided'], array_values(array_unique(array_map(fn ($row) => $row->action, $this->auditRows(['module' => 'purchase'])->filter(fn ($row) => $row->action !== 'receipt_created')->all()))));
    }

    public function test_the_database_refuses_an_overpaid_receipt(): void
    {
        $w = $this->world();
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)])->assertCreated();

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('purchase_receipts')->update(['paid_amount' => 10000.01]));
    }

    public function test_the_list_filters_and_sums_the_whole_filtered_set(): void
    {
        $w = $this->world();
        $second = $this->api($w['owner'])->postJson('/api/v1/suppliers', ['name' => 'Đại lý Thuốc Xanh'])->assertCreated()->json('data');
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['supplier_ref' => 'HD-100', 'received_on' => '2026-10-01', 'paid_amount' => 10000])->assertCreated();
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 20, 1000)], ['supplier_ref' => 'HD-200', 'received_on' => '2026-10-15', 'paid_amount' => 5000])->assertCreated();
        $this->api($w['owner'])->postJson('/api/v1/purchases', ['warehouse_id' => $w['khoA']->uuid, 'supplier_id' => $second['id'], 'received_on' => '2026-10-20', 'lines' => [$this->line($w['npk'], $w['kg']->uuid, 5, 2000)]])->assertCreated();

        $list = $this->api($w['owner'])->getJson('/api/v1/purchases')->assertOk();
        $this->assertSame(['NH0003', 'NH0002', 'NH0001'], array_column($list->json('data'), 'code'), 'ngày nhập mới nhất trước');
        $list->assertJsonPath('summary.goods_total', '40000.00')->assertJsonPath('summary.paid_amount', '15000.00')->assertJsonPath('summary.balance', '25000.00');
        $this->assertSame([1, 1, 1], array_column($list->json('data'), 'line_count'));
        $this->assertArrayNotHasKey('lines', $list->json('data.0'));

        $codes = fn (string $query) => array_column($this->api($w['owner'])->getJson("/api/v1/purchases?{$query}")->assertOk()->json('data'), 'code');
        $this->assertSame(['NH0002'], $codes('q=HD-200'));
        $this->assertSame(['NH0001'], $codes('q=0001'));
        $this->assertSame(['NH0003'], $codes('supplier='.$second['id']));
        $this->assertSame(['NH0002'], $codes('payment_status=partial'));
        $this->assertSame(['NH0003'], $codes('payment_status=unpaid'));
        $this->assertSame(['NH0001'], $codes('payment_status=paid'));
        $this->assertSame(['NH0003', 'NH0002'], $codes('payment_status=open'));
        $this->assertSame(['NH0003', 'NH0002'], $codes('from=2026-10-10'));
        $this->assertSame(['NH0001'], $codes('to=2026-10-10'));
        $this->assertSame([$w['khoA']->code], array_unique(array_column(array_column($this->api($w['owner'])->getJson("/api/v1/purchases?warehouse={$w['khoA']->uuid}")->json('data'), 'warehouse'), 'code')));
        $this->api($w['owner'])->getJson('/api/v1/purchases?payment_status=lạ')->assertUnprocessable();

        // Tổng kết tính trên mọi phiếu khớp bộ lọc, không chỉ trang đang xem.
        $page = $this->api($w['owner'])->getJson('/api/v1/purchases?per_page=1&supplier='.$w['supplier']['id'])->assertOk();
        $this->assertCount(1, $page->json('data'));
        $page->assertJsonPath('summary.goods_total', '30000.00')->assertJsonPath('summary.balance', '15000.00')->assertJsonPath('meta.total', 2);
    }

    public function test_the_stock_document_points_back_to_its_purchase_receipt(): void
    {
        $w = $this->world();
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)])->assertCreated();
        $this->postStock($w['owner'], $w['khoA'], 'receipt', [['product_id' => $w['npk']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();

        $documents = $this->api($w['owner'])->getJson('/api/v1/stock/documents')->assertOk()->json('data');
        $this->assertSame(['NH0001', null], [$documents[1]['purchase']['code'] ?? null, $documents[0]['purchase'] ?? null], 'phiếu nhập kho nào do mua hàng sinh ra thì có liên kết, phiếu lập tay thì không');
    }

    public function test_warehouse_staff_receive_goods_but_cannot_pay_and_other_roles_cannot_receive(): void
    {
        $w = $this->world();
        $keeper = $this->makeUserIn($w['tenant'], ['warehouse'], $w['branchA']);
        $line = [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)];

        // Thủ kho lập phiếu được (không trả tiền); trả tiền ngay khi lập hoặc trả thêm sau đó cần quyền cập nhật mà thủ kho không có.
        $id = $this->receive($w, $line, [], $keeper)->assertCreated()->json('data.id');
        $this->receive($w, $line, ['paid_amount' => 100], $keeper)->assertForbidden();
        $this->api($keeper)->postJson("/api/v1/purchases/{$id}/payments", ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->api($keeper)->getJson("/api/v1/purchases/{$id}")->assertOk();
        $this->assertSame(1, $this->platform(fn () => PurchaseReceipt::count()));

        // Chỉ chi nhánh của mình.
        $this->receive($w, [], [], $keeper, $w['khoB'])->assertUnprocessable(); // hình dạng sai trước
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1)], [], $keeper, $w['khoB'])->assertForbidden();

        foreach (['sales', 'delivery'] as $role) {
            $user = $this->makeUserIn($w['tenant'], [$role], $w['branchA']);
            $this->api($user)->getJson('/api/v1/purchases')->assertForbidden();
            $this->api($user)->getJson("/api/v1/purchases/{$id}")->assertForbidden();
            $this->receive($w, $line, [], $user)->assertForbidden();
        }

        // Kế toán chỉ xem (và xuất), không lập, không trả.
        $accountant = $this->makeUserIn($w['tenant'], ['accountant'], $w['branchA']);
        $this->api($accountant)->getJson('/api/v1/purchases')->assertOk();
        $this->receive($w, $line, [], $accountant)->assertForbidden();
        $this->api($accountant)->postJson("/api/v1/purchases/{$id}/payments", ['amount' => 100, 'method' => 'cash'])->assertForbidden();
    }

    public function test_a_branch_manager_pays_and_voids_inside_their_branch_only(): void
    {
        $w = $this->world();
        $manager = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchA']);
        $managerB = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchB']);
        $id = $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['paid_amount' => 1000], $manager)->assertCreated()->json('data.id');

        $this->api($manager)->postJson("/api/v1/purchases/{$id}/payments", ['amount' => 2000, 'method' => 'cash'])->assertOk();
        $payment = $this->api($manager)->getJson("/api/v1/purchases/{$id}")->json('data.payments.0.id');
        $this->api($manager)->postJson("/api/v1/purchases/{$id}/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertOk();

        $this->api($managerB)->getJson("/api/v1/purchases/{$id}")->assertForbidden();
        $this->api($managerB)->postJson("/api/v1/purchases/{$id}/payments", ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->api($managerB)->postJson("/api/v1/purchases/{$id}/payments/{$payment}/void", ['reason' => 'Thử'])->assertForbidden();
        $this->assertSame([], $this->api($managerB)->getJson('/api/v1/purchases')->json('data'));
        $this->assertSame([$id], array_column($this->api($manager)->getJson('/api/v1/purchases')->json('data'), 'id'));
    }

    public function test_purchases_survive_a_backup_and_restore(): void
    {
        $w = $this->world();
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['paid_amount' => 3000, 'extra_cost' => 500])->assertCreated();
        $snapshot = fn () => $this->platform(fn () => [
            DB::table('purchase_receipts')->orderBy('id')->get(['id', 'uuid', 'code', 'goods_total', 'extra_cost', 'paid_amount', 'stock_document_id'])->map(fn ($r) => (array) $r)->all(),
            DB::table('purchase_receipt_lines')->orderBy('id')->get(['id', 'receipt_id', 'product_id', 'line_total', 'extra_allocated'])->map(fn ($r) => (array) $r)->all(),
            DB::table('purchase_payments')->orderBy('id')->get(['id', 'uuid', 'receipt_id', 'amount'])->map(fn ($r) => (array) $r)->all(),
        ]);
        $before = $snapshot();

        $backup = $this->adminBackup($w['tenant']);
        $this->assertSame(1, $backup->row_counts['purchase_receipts']);
        $this->assertSame(1, $backup->row_counts['purchase_payments']);

        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)], ['paid_amount' => 100])->assertCreated();
        $this->adminRestore($backup, $w['tenant']->name)->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $snapshot());
        $this->receive($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)])->assertCreated()->assertJsonPath('data.code', 'NH0002');
    }
}
