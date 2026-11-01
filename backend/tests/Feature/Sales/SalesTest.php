<?php

namespace Tests\Feature\Sales;

use App\Models\Batch;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\Warehouse;
use Carbon\Carbon;
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
 * Bán hàng: quy trình MỘT bước cho cửa hàng nhỏ — lập phiếu bán là hàng xuất kho ngay (qua StockService), khách trả đủ hoặc ghi nợ; sản phẩm theo dõi lô tự chọn lô gần hết hạn
 * trước; bán chịu bị chặn khi vượt hạn mức / quá hạn nợ; thu thêm tiền, hủy lần thu nhầm. Chủ sản phẩm yêu cầu quy trình đơn giản (2026-11-02).
 */
class SalesTest extends TestCase
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

    private function today(int $plusDays = 0): string
    {
        return Carbon::now(config('inventory.timezone'))->addDays($plusDays)->toDateString();
    }

    /**
     * Cửa hàng có hai chi nhánh A (kho khoA) và B (kho khoB); NPK-16 (Kg, Bao = 50 Kg, đã nhập 100 Kg giá vốn 10.000/Kg) và thuốc TS-01 (theo dõi lô) ở chi nhánh A;
     * khách `nong` (hạn mức 1.000.000, nợ tối đa 30 ngày) và khách `khongno` (hạn mức 0) do chi nhánh A phụ trách.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $khoA = $this->warehouseOf($branchA);
        $npk = $this->makeProduct($owner, $branchA, $kg, $bao, 'NPK-16');
        $this->postStock($owner, $khoA, 'receipt', [['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 100, 'unit_cost' => 10000]])->assertCreated();

        $customer = fn (array $extra) => $this->api($owner)->postJson('/api/v1/customers', array_merge(['branch_id' => $branchA->uuid, 'type' => 'individual'], $extra))->assertCreated()->json('data');

        return [
            'tenant' => $tenant, 'owner' => $owner, 'branchA' => $branchA, 'branchB' => $branchB, 'kg' => $kg, 'bao' => $bao,
            'khoA' => $khoA, 'khoB' => $this->warehouseOf($branchB),
            'npk' => $npk, 'drug' => $this->makeProduct($owner, $branchA, $kg, $bao, 'TS-01', true),
            'nong' => $customer(['name' => 'Nguyễn Văn Nông', 'credit_limit' => 1000000, 'credit_days' => 30]),
            'khongno' => $customer(['name' => 'Trần Thị Mặt', 'credit_limit' => 0]),
        ];
    }

    private function line(array $product, string $unit, int|float $quantity, int|float $price): array
    {
        return ['product_id' => $product['id'], 'unit_id' => $unit, 'quantity' => $quantity, 'unit_price' => $price];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function sell(array $w, array $lines, array $extra = [], ?object $user = null, ?Warehouse $kho = null): TestResponse
    {
        return $this->api($user ?? $w['owner'])->postJson('/api/v1/sales', array_merge(['warehouse_id' => ($kho ?? $w['khoA'])->uuid, 'lines' => $lines], $extra));
    }

    /** Nhập thuốc vào 3 lô: gần hết hạn (5 Kg), xa (10 Kg) và một lô ĐÃ QUÁ HẠN (7 Kg). */
    private function stockDrug(array $w): void
    {
        $lot = fn (string $no, string $expires, int $qty) => ['product_id' => $w['drug']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => $qty, 'unit_cost' => 50000, 'batch_no' => $no, 'expires_at' => $expires];
        $this->postStock($w['owner'], $w['khoA'], 'receipt', [
            $lot('L-XA', $this->today(730), 10), $lot('L-GAN', $this->today(365), 5), $lot('L-HET', '2020-01-01', 7),
        ])->assertCreated();
    }

    private function productId(array $product): int
    {
        return $this->platform(fn () => Product::where('uuid', $product['id'])->value('id'));
    }

    private function stock(Warehouse $kho, array $product, ?string $batchNo = null): float
    {
        $id = $this->productId($product);

        return (float) $this->platform(fn () => StockBalance::where('warehouse_id', $kho->id)->where('product_id', $id)
            ->when($batchNo, fn ($q) => $q->whereIn('batch_id', Batch::where('batch_no', $batchNo)->select('id')))->sum('quantity'));
    }

    private function nothingWritten(): void
    {
        $this->assertSame(0, $this->platform(fn () => SalesOrder::count()));
        $this->assertSame(1, $this->platform(fn () => StockDocument::count()), 'chỉ còn phiếu nhập kho ban đầu');
    }

    public function test_a_cash_sale_takes_the_goods_out_of_stock_at_once_with_the_cost_and_the_payment(): void
    {
        $w = $this->world();

        $response = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 15500), $this->line($w['npk'], $w['bao']->uuid, 1, 750000)], [
            'note' => ' Lấy tại quầy ', 'sold_on' => $this->today(-1), 'paid_amount' => 905000, 'payment_method' => 'bank_transfer',
        ])->assertCreated();

        $response->assertJsonPath('data.code', 'BH0001')->assertJsonPath('data.note', 'Lấy tại quầy')->assertJsonPath('data.customer', null)
            ->assertJsonPath('data.goods_total', '905000.00')->assertJsonPath('data.discount', '0.00')->assertJsonPath('data.total', '905000.00')
            ->assertJsonPath('data.paid_amount', '905000.00')->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.is_overdue', false)->assertJsonPath('data.due_on', null)->assertJsonPath('data.stock_document.code', 'PX0001')
            ->assertJsonPath('data.created_by.name', $w['owner']->name)->assertJsonPath('data.warehouse.code', 'KHO-01')
            ->assertJsonPath('data.lines.0.product.sku', 'NPK-16')->assertJsonPath('data.lines.0.unit', 'Kg')->assertJsonPath('data.lines.0.quantity', '10.000')
            ->assertJsonPath('data.lines.0.unit_price', '15500.00')->assertJsonPath('data.lines.0.line_total', '155000.00')->assertJsonPath('data.lines.0.cost_amount', '100000.00')
            ->assertJsonPath('data.lines.1.unit', 'Bao')->assertJsonPath('data.lines.1.line_total', '750000.00')->assertJsonPath('data.lines.1.cost_amount', '500000.00')
            ->assertJsonPath('data.payments.0.amount', '905000.00')->assertJsonPath('data.payments.0.method', 'bank_transfer')->assertJsonPath('data.payments.0.paid_on', $this->today(-1));

        // 10 Kg + 1 Bao (50 Kg) đã ra khỏi kho; sổ kho ghi phiếu xuất với lý do "bán hàng".
        $this->assertSame(40.0, $this->stock($w['khoA'], $w['npk']));
        $document = $this->platform(fn () => StockDocument::where('code', 'PX0001')->firstOrFail());
        $this->assertSame('issue', $document->type);
        $this->assertSame('sale', $document->reason);
        $this->assertStringContainsString('BH0001', (string) $document->note);

        $audit = $this->lastAudit(['module' => 'sales', 'action' => 'order_created']);
        $this->assertSame('BH0001', $audit->after['code']);
        $this->assertSame($w['branchA']->id, $audit->branch_id);
    }

    public function test_omitting_the_paid_amount_means_the_customer_pays_in_full_and_a_discount_lowers_the_total(): void
    {
        $w = $this->world();

        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 15500)], ['discount' => 5000])->assertCreated()
            ->assertJsonPath('data.goods_total', '155000.00')->assertJsonPath('data.discount', '5000.00')->assertJsonPath('data.total', '150000.00')
            ->assertJsonPath('data.paid_amount', '150000.00')->assertJsonPath('data.payment_status', 'paid');

        $big = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 15500)], ['discount' => 15500.01])->assertUnprocessable();
        $big->assertJsonValidationErrors(['discount']);
        $this->assertStringContainsString('không được lớn hơn tổng tiền hàng', $big->json('errors.discount.0'));

        $over = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 15500)], ['paid_amount' => 15500.01])->assertUnprocessable();
        $this->assertStringContainsString('không được lớn hơn số tiền khách phải trả', $over->json('errors.paid_amount.0'));
        $this->assertSame(1, $this->platform(fn () => SalesOrder::count()));
    }

    public function test_batch_tracked_products_are_picked_by_the_nearest_expiry_and_expired_lots_are_never_sold(): void
    {
        $w = $this->world();
        $this->stockDrug($w);

        // 8 Kg: lấy hết lô gần hết hạn (5) rồi 3 từ lô xa; lô đã quá hạn (7) bị bỏ qua.
        $response = $this->sell($w, [$this->line($w['drug'], $w['kg']->uuid, 8, 16000)])->assertCreated();
        $response->assertJsonPath('data.goods_total', '128000.00')->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.batch.batch_no', 'L-GAN')->assertJsonPath('data.lines.0.quantity', '5.000')->assertJsonPath('data.lines.0.line_total', '80000.00')
            ->assertJsonPath('data.lines.1.batch.batch_no', 'L-XA')->assertJsonPath('data.lines.1.quantity', '3.000')->assertJsonPath('data.lines.1.line_total', '48000.00')
            ->assertJsonPath('data.lines.0.cost_amount', '250000.00');
        $this->assertSame(0.0, $this->stock($w['khoA'], $w['drug'], 'L-GAN'));
        $this->assertSame(7.0, $this->stock($w['khoA'], $w['drug'], 'L-XA'));
        $this->assertSame(7.0, $this->stock($w['khoA'], $w['drug'], 'L-HET'));

        // Còn 7 Kg còn hạn: bán 8 thì báo thiếu (không tính lô hết hạn) ở đúng dòng, không ghi gì thêm.
        $short = $this->sell($w, [$this->line($w['drug'], $w['kg']->uuid, 8, 16000)])->assertUnprocessable();
        $short->assertJsonValidationErrors(['lines.0.quantity']);
        $this->assertStringContainsString('còn 7 Kg, cần 8 Kg', $short->json('errors')['lines.0.quantity'][0]);
        $this->assertSame(1, $this->platform(fn () => SalesOrder::count()));
        $this->assertSame(7.0, $this->stock($w['khoA'], $w['drug'], 'L-XA'));
    }

    public function test_a_batch_split_in_a_larger_unit_keeps_the_quantity_and_the_line_total_exact(): void
    {
        $w = $this->world();
        $this->stockDrug($w);
        $this->sell($w, [$this->line($w['drug'], $w['kg']->uuid, 3, 16000)])->assertCreated(); // L-GAN còn 2 Kg, L-XA 10 Kg

        // 0,2 Bao = 10 Kg: 2 Kg lô gần hết hạn (0,04 Bao) + 8 Kg lô xa (0,16 Bao); thành tiền 0,2 × 780.000 = 156.000 khớp tổng hai phần.
        $response = $this->sell($w, [$this->line($w['drug'], $w['bao']->uuid, 0.2, 780000)])->assertCreated();
        $response->assertJsonPath('data.lines.0.quantity', '0.040')->assertJsonPath('data.lines.1.quantity', '0.160')->assertJsonPath('data.goods_total', '156000.00');
        $this->assertSame(156000.0, round((float) $response->json('data.lines.0.line_total') + (float) $response->json('data.lines.1.line_total'), 2));
    }

    public function test_a_failure_in_the_stock_step_rolls_back_the_sale_and_names_the_line_the_seller_typed(): void
    {
        $w = $this->world();
        $this->stockDrug($w);
        $far = $this->platform(fn () => Batch::where('batch_no', 'L-XA')->value('uuid'));
        $this->postStock($w['owner'], $w['khoA'], 'issue', [['product_id' => $w['drug']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 10, 'batch_id' => $far]], ['reason' => 'sample'])->assertCreated();
        $before = $this->platform(fn () => StockDocument::count());

        // Dòng 0 chia thành nhiều dòng phiếu kho, dòng 1 vượt tồn: lỗi báo ở dòng 1 chứ không phải vị trí sau khi chia.
        $this->sell($w, [$this->line($w['drug'], $w['kg']->uuid, 5, 16000), $this->line($w['npk'], $w['kg']->uuid, 101, 15500)])->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.quantity']);
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 15500)], [], null, $w['khoB'])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);

        $this->assertSame(0, $this->platform(fn () => SalesOrder::count()));
        $this->assertSame($before, $this->platform(fn () => StockDocument::count()));
        $this->assertSame(5.0, $this->stock($w['khoA'], $w['drug'], 'L-GAN'));

        // Kho đang kiểm kê bị khóa.
        $count = $this->api($w['owner'])->postJson('/api/v1/stocktakes', ['warehouse_id' => $w['khoA']->uuid])->assertCreated()->json('data.id');
        $this->assertStringContainsString('đang kiểm kê', $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 15500)])->assertUnprocessable()->json('errors.warehouse_id.0'));
        $this->api($w['owner'])->postJson("/api/v1/stocktakes/{$count}/cancel")->assertOk();
    }

    public function test_the_request_shape_the_customer_and_the_date_are_validated(): void
    {
        $w = $this->world();
        $good = [$this->line($w['npk'], $w['kg']->uuid, 1, 15500)];

        $this->sell($w, [])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 0, 15500)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, -1)])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.unit_price']);
        $this->sell($w, $good, ['sold_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['sold_on']);
        $this->sell($w, $good, ['discount' => -1])->assertUnprocessable()->assertJsonValidationErrors(['discount']);
        $this->sell($w, $good, ['payment_method' => 'bitcoin', 'paid_amount' => 10])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);
        $this->sell($w, $good, ['customer_id' => (string) fake()->uuid()])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);

        // Khách ngừng giao dịch thì không bán được, kể cả trả đủ.
        $this->api($w['owner'])->patchJson('/api/v1/customers/'.$w['nong']['id'], ['status' => 'inactive'])->assertOk();
        $this->sell($w, $good, ['customer_id' => $w['nong']['id']])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
        $this->assertSame(0, $this->platform(fn () => SalesOrder::count()));
    }

    public function test_selling_on_credit_needs_a_customer_with_a_credit_limit_and_records_the_due_date(): void
    {
        $w = $this->world();
        $line = [$this->line($w['npk'], $w['kg']->uuid, 10, 16000)]; // 160.000

        // Khách lẻ phải trả đủ; khách hạn mức 0 cũng vậy.
        $walkIn = $this->sell($w, $line, ['paid_amount' => 100000])->assertUnprocessable();
        $this->assertStringContainsString('Khách lẻ phải trả đủ tiền', $walkIn->json('errors.customer_id.0'));
        $zero = $this->sell($w, $line, ['customer_id' => $w['khongno']['id'], 'paid_amount' => 0])->assertUnprocessable();
        $this->assertStringContainsString('chưa được phép ghi nợ', $zero->json('errors.customer_id.0'));
        $this->assertSame(0, $this->platform(fn () => SalesOrder::count()));

        // Khách hạn mức 1.000.000, nợ 30 ngày: trả trước 60.000, nợ 100.000, hạn trả = ngày bán + 30.
        $this->sell($w, $line, ['customer_id' => $w['khongno']['id']])->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->sell($w, $line, ['customer_id' => $w['nong']['id'], 'paid_amount' => 60000, 'sold_on' => $this->today(-5)])->assertCreated()
            ->assertJsonPath('data.customer.name', 'Nguyễn Văn Nông')->assertJsonPath('data.balance', '100000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.due_on', $this->today(25))->assertJsonPath('data.is_overdue', false);
    }

    public function test_credit_sales_are_blocked_beyond_the_limit_counting_every_open_sale_of_the_customer(): void
    {
        $w = $this->world();
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 70000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertCreated()->assertJsonPath('data.balance', '700000.00');

        // Đang nợ 700.000, ghi nợ thêm 400.000 là 1.100.000 > 1.000.000.
        $blocked = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 40000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertUnprocessable();
        $this->assertStringContainsString('Vượt hạn mức công nợ', $blocked->json('errors.customer_id.0'));
        $this->assertStringContainsString('700.000 đ', $blocked->json('errors.customer_id.0'));
        $this->assertSame(1, $this->platform(fn () => SalesOrder::count()));
        $this->assertSame(90.0, $this->stock($w['khoA'], $w['npk']));

        // Trả bớt tại phiếu (nợ mới 300.000 → tổng 1.000.000 đúng bằng hạn mức) thì được; nay hết hạn mức.
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 40000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 100000])->assertCreated()->assertJsonPath('data.balance', '300000.00');
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertUnprocessable();

        // Khách trả đủ thì được bán tiếp, không bị chặn.
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)], ['customer_id' => $w['nong']['id']])->assertCreated();
    }

    public function test_a_customer_with_an_overdue_debt_cannot_buy_on_credit_until_it_is_collected(): void
    {
        $w = $this->world();
        $line = [$this->line($w['npk'], $w['kg']->uuid, 1, 10000)];
        $id = $this->sell($w, $line, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0, 'sold_on' => $this->today(-40)])->assertCreated()
            ->assertJsonPath('data.due_on', $this->today(-10))->assertJsonPath('data.is_overdue', true)->json('data.id');

        $blocked = $this->sell($w, $line, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertUnprocessable();
        $this->assertStringContainsString('nợ quá hạn phiếu BH0001', $blocked->json('errors.customer_id.0'));

        // Trả đủ tiền mặt thì vẫn mua được (không có nợ mới); thu hết nợ cũ thì mua chịu lại được.
        $this->sell($w, $line, ['customer_id' => $w['nong']['id']])->assertCreated();
        $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments", ['amount' => 10000, 'method' => 'cash'])->assertOk()->assertJsonPath('data.is_overdue', false);
        $this->sell($w, $line, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertCreated();
    }

    public function test_collecting_in_several_steps_never_beyond_the_debt_and_a_wrong_collection_can_be_voided(): void
    {
        $w = $this->world();
        $id = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 50000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 100000])->assertCreated()->json('data.id'); // 500.000
        $collect = fn (array $body) => $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments", $body);

        $collect(['amount' => 150000, 'method' => 'cash', 'note' => 'Khách đưa thêm', 'paid_on' => $this->today(-1)])->assertOk()
            ->assertJsonPath('data.paid_amount', '250000.00')->assertJsonPath('data.balance', '250000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.1.note', 'Khách đưa thêm')->assertJsonPath('data.payments.1.created_by.name', $w['owner']->name);

        $over = $collect(['amount' => 250000.01, 'method' => 'cash'])->assertUnprocessable();
        $this->assertStringContainsString('vượt quá số khách còn nợ', $over->json('errors.amount.0'));
        $collect(['amount' => 0, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $collect(['amount' => 100, 'method' => 'momo'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $collect(['amount' => 100, 'method' => 'cash', 'paid_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['paid_on']);

        $collect(['amount' => 250000, 'method' => 'bank_transfer'])->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.balance', '0.00');
        $collect(['amount' => 1, 'method' => 'cash'])->assertUnprocessable();

        // Thu nhầm: hủy lần thứ hai thì khách lại còn nợ; lần đã hủy vẫn nằm trong lịch sử kèm lý do.
        $second = $this->api($w['owner'])->getJson("/api/v1/sales/{$id}")->json('data.payments.1.id');
        $void = fn (string $payment, array $body) => $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments/{$payment}/void", $body);
        $void($second, [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $void($second, ['reason' => 'Ghi nhầm số tiền'])->assertOk()->assertJsonPath('data.paid_amount', '350000.00')->assertJsonPath('data.balance', '150000.00')
            ->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.payments.1.voided.reason', 'Ghi nhầm số tiền')->assertJsonPath('data.payments.1.voided.by.name', $w['owner']->name)
            ->assertJsonPath('data.payments.0.voided', null);
        $void($second, ['reason' => 'Lần nữa'])->assertUnprocessable()->assertJsonValidationErrors(['payment']);
        $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments/".fake()->uuid().'/void', ['reason' => 'Không có'])->assertNotFound();

        $collect(['amount' => 150000, 'method' => 'cash'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame(4, $this->platform(fn () => SalesPayment::count()), 'không có lần thu nào bị xóa');
        $this->assertSame(['payment_recorded', 'payment_voided'], array_values(array_unique(array_map(fn ($row) => $row->action, $this->auditRows(['module' => 'sales'])->filter(fn ($row) => $row->action !== 'order_created')->all()))));
    }

    public function test_the_database_refuses_an_overpaid_order_and_a_debt_without_a_customer(): void
    {
        $w = $this->world();
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)])->assertCreated();

        try {
            $this->platform(fn () => DB::transaction(fn () => DB::table('sales_orders')->update(['paid_amount' => 10000.01])));
            $this->fail('Phiếu thu vượt số phải trả phải bị từ chối.');
        } catch (QueryException) {
            $this->assertSame('10000.00', (string) $this->platform(fn () => DB::table('sales_orders')->value('paid_amount')));
        }

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('sales_orders')->update(['paid_amount' => 5000]));
    }

    public function test_the_list_filters_and_sums_the_whole_filtered_set(): void
    {
        $w = $this->world();
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['sold_on' => $this->today(-20)])->assertCreated(); // BH0001 10.000 đã trả đủ
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 20, 1000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 5000, 'sold_on' => $this->today(-10)])->assertCreated(); // BH0002 20.000 đã trả 5.000
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 5, 2000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 0, 'sold_on' => $this->today(-40)])->assertCreated(); // BH0003 10.000 nợ, quá hạn

        $list = $this->api($w['owner'])->getJson('/api/v1/sales')->assertOk();
        $this->assertSame(['BH0002', 'BH0001', 'BH0003'], array_column($list->json('data'), 'code'), 'ngày bán mới nhất trước');
        $list->assertJsonPath('summary.total', '40000.00')->assertJsonPath('summary.paid_amount', '15000.00')->assertJsonPath('summary.balance', '25000.00');
        $this->assertSame([1, 1, 1], array_column($list->json('data'), 'line_count'));
        $this->assertArrayNotHasKey('lines', $list->json('data.0'));

        $codes = fn (string $query) => array_column($this->api($w['owner'])->getJson("/api/v1/sales?{$query}")->assertOk()->json('data'), 'code');
        $this->assertSame(['BH0001'], $codes('q=0001'));
        $this->assertSame(['BH0002', 'BH0003'], $codes('customer='.$w['nong']['id']));
        $this->assertSame(['BH0002'], $codes('payment_status=partial'));
        $this->assertSame(['BH0003'], $codes('payment_status=unpaid'));
        $this->assertSame(['BH0001'], $codes('payment_status=paid'));
        $this->assertSame(['BH0002', 'BH0003'], $codes('payment_status=open'));
        $this->assertSame(['BH0003'], $codes('payment_status=overdue'));
        $this->assertSame(['BH0002', 'BH0001'], $codes('from='.$this->today(-25)));
        $this->assertSame(['BH0001', 'BH0003'], $codes('to='.$this->today(-15)));
        $this->assertSame(['BH0002', 'BH0001', 'BH0003'], $codes('warehouse='.$w['khoA']->uuid));
        $this->api($w['owner'])->getJson('/api/v1/sales?payment_status=lạ')->assertUnprocessable();

        // Tổng kết tính trên mọi phiếu khớp bộ lọc, không chỉ trang đang xem.
        $page = $this->api($w['owner'])->getJson('/api/v1/sales?per_page=1&customer='.$w['nong']['id'])->assertOk();
        $this->assertCount(1, $page->json('data'));
        $page->assertJsonPath('summary.total', '30000.00')->assertJsonPath('summary.balance', '25000.00')->assertJsonPath('meta.total', 2);
    }

    public function test_the_stock_document_points_back_to_its_sale_and_the_sale_reason_cannot_be_used_by_hand(): void
    {
        $w = $this->world();
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)])->assertCreated();

        $documents = $this->api($w['owner'])->getJson('/api/v1/stock/documents')->assertOk()->json('data');
        $this->assertSame(['BH0001', 'sale'], [$documents[0]['sale']['code'] ?? null, $documents[0]['reason']]);
        $this->assertNull($documents[1]['sale'] ?? null, 'phiếu nhập kho lập tay không có liên kết bán hàng');

        // Người dùng không tự xuất kho với lý do "bán hàng" (kể cả gài khóa nội bộ): chỉ Bán hàng mới sinh phiếu như vậy.
        $line = [['product_id' => $w['npk']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 1]];
        $this->postStock($w['owner'], $w['khoA'], 'issue', $line, ['reason' => 'sale'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postStock($w['owner'], $w['khoA'], 'issue', $line, ['reason' => 'other', 'note' => 'x', 'system_reason' => 'sale'])->assertCreated()->assertJsonPath('data.reason', 'other');
    }

    public function test_costs_are_shown_only_to_people_who_may_see_them(): void
    {
        $w = $this->world();
        $seller = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $id = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 15500)], [], $seller)->assertCreated()->assertJsonMissingPath('data.lines.0.cost_amount')->json('data.id');

        $this->api($seller)->getJson("/api/v1/sales/{$id}")->assertOk()->assertJsonMissingPath('data.lines.0.cost_amount');
        $this->api($w['owner'])->getJson("/api/v1/sales/{$id}")->assertOk()->assertJsonPath('data.lines.0.cost_amount', '100000.00');
    }

    public function test_a_salesperson_sells_in_their_branch_and_collects_only_on_their_own_sales(): void
    {
        $w = $this->world();
        $seller = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $other = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $line = [$this->line($w['npk'], $w['kg']->uuid, 10, 50000)];

        // Bán chịu cho khách của chi nhánh mình; thu thêm và hủy lần thu trên phiếu của chính mình.
        $mine = $this->sell($w, $line, ['customer_id' => $w['nong']['id'], 'paid_amount' => 100000], $seller)->assertCreated()->json('data.id');
        $this->api($seller)->postJson("/api/v1/sales/{$mine}/payments", ['amount' => 100000, 'method' => 'cash'])->assertOk();
        $payment = $this->api($seller)->getJson("/api/v1/sales/{$mine}")->json('data.payments.1.id');
        $this->api($seller)->postJson("/api/v1/sales/{$mine}/payments/{$payment}/void", ['reason' => 'Nhầm'])->assertOk();

        // Cùng chi nhánh xem được, nhưng không thu / hủy hộ phiếu của người khác (phạm vi `own`).
        $this->api($other)->getJson("/api/v1/sales/{$mine}")->assertOk();
        $this->api($other)->postJson("/api/v1/sales/{$mine}/payments", ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->api($other)->postJson("/api/v1/sales/{$mine}/payments/{$payment}/void", ['reason' => 'Thử'])->assertForbidden();

        // Không bán từ kho chi nhánh khác.
        $this->sell($w, $line, [], $seller, $w['khoB'])->assertForbidden();
    }

    public function test_a_branch_scoped_seller_cannot_sell_to_a_customer_of_another_branch(): void
    {
        $w = $this->world();
        $sellerB = $this->makeUserIn($w['tenant'], ['sales'], $w['branchB']);
        $kg = $w['kg'];
        $bao = $w['bao'];
        $productB = $this->makeProduct($w['owner'], $w['branchB'], $kg, $bao, 'NPK-B');
        $this->postStock($w['owner'], $w['khoB'], 'receipt', [['product_id' => $productB['id'], 'unit_id' => $kg->uuid, 'quantity' => 10, 'unit_cost' => 1000]])->assertCreated();

        $denied = $this->sell($w, [$this->line($productB, $kg->uuid, 1, 2000)], ['customer_id' => $w['nong']['id']], $sellerB, $w['khoB'])->assertUnprocessable();
        $this->assertStringContainsString('khách thuộc chi nhánh khác', $denied->json('errors.customer_id.0'));
        $this->sell($w, [$this->line($productB, $kg->uuid, 1, 2000)], [], $sellerB, $w['khoB'])->assertCreated();
    }

    public function test_other_roles_only_read_or_cannot_reach_sales(): void
    {
        $w = $this->world();
        $id = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)])->assertCreated()->json('data.id');
        $line = [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)];

        // Kế toán và thủ kho xem được, không lập phiếu, không thu tiền.
        foreach (['accountant', 'warehouse'] as $role) {
            $user = $this->makeUserIn($w['tenant'], [$role], $w['branchA']);
            $this->api($user)->getJson('/api/v1/sales')->assertOk();
            $this->api($user)->getJson("/api/v1/sales/{$id}")->assertOk();
            $this->sell($w, $line, [], $user)->assertForbidden();
            $this->api($user)->postJson("/api/v1/sales/{$id}/payments", ['amount' => 1, 'method' => 'cash'])->assertForbidden();
        }

        // Nhân viên giao hàng (chỉ phạm vi `own`) chưa vào được danh sách.
        $delivery = $this->makeUserIn($w['tenant'], ['delivery'], $w['branchA']);
        $this->api($delivery)->getJson('/api/v1/sales')->assertForbidden();
        $this->sell($w, $line, [], $delivery)->assertForbidden();

        $this->api($w['owner'])->getJson('/api/v1/sales/khong-phai-uuid')->assertNotFound();
    }

    public function test_a_manager_of_another_branch_cannot_see_the_sale(): void
    {
        $w = $this->world();
        $id = $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)])->assertCreated()->json('data.id');
        $managerA = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchA']);
        $managerB = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchB']);

        $this->api($managerB)->getJson("/api/v1/sales/{$id}")->assertForbidden();
        $this->assertSame([], $this->api($managerB)->getJson('/api/v1/sales')->json('data'));
        $this->assertSame([$id], array_column($this->api($managerA)->getJson('/api/v1/sales')->json('data'), 'id'));
    }

    public function test_sales_survive_a_backup_and_restore(): void
    {
        $w = $this->world();
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 10, 1000)], ['customer_id' => $w['nong']['id'], 'paid_amount' => 3000, 'discount' => 500])->assertCreated();
        $snapshot = fn () => $this->platform(fn () => [
            DB::table('sales_orders')->orderBy('id')->get(['id', 'uuid', 'code', 'goods_total', 'discount', 'total', 'paid_amount', 'due_on', 'stock_document_id'])->map(fn ($r) => (array) $r)->all(),
            DB::table('sales_order_lines')->orderBy('id')->get(['id', 'order_id', 'product_id', 'line_total', 'cost_amount'])->map(fn ($r) => (array) $r)->all(),
            DB::table('sales_payments')->orderBy('id')->get(['id', 'uuid', 'order_id', 'amount'])->map(fn ($r) => (array) $r)->all(),
        ]);
        $before = $snapshot();

        $backup = $this->adminBackup($w['tenant']);
        $this->assertSame(1, $backup->row_counts['sales_orders']);
        $this->assertSame(1, $backup->row_counts['sales_payments']);

        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)])->assertCreated();
        $this->adminRestore($backup, $w['tenant']->name)->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $snapshot());
        $this->sell($w, [$this->line($w['npk'], $w['kg']->uuid, 1, 1000)])->assertCreated()->assertJsonPath('data.code', 'BH0002');
    }
}
