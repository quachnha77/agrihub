<?php

namespace Tests\Feature\Sales;

use App\Models\SalesOrder;
use App\Models\SalesPayment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/**
 * Công nợ khách hàng: danh sách khách đang nợ theo tuổi nợ và thu một khoản cho nhiều phiếu cùng lúc (phiếu đến hạn sớm nhất trước). Dữ liệu lấy từ phiếu bán,
 * không có sổ riêng; mỗi người chỉ thấy / thu phần nợ thuộc phạm vi quyền `sales.order.*` của mình.
 */
class ReceivablesTest extends TestCase
{
    use CreatesTenants, MakesCatalog, MakesStock, ReadsAuditLog, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    private function today(int $plusDays = 0): string
    {
        return Carbon::now(config('inventory.timezone'))->addDays($plusDays)->toDateString();
    }

    /**
     * Hai chi nhánh A (kho khoA) và B; NPK-16 ở A (500 Kg) và NPK-B ở B; ba khách do A phụ trách: `nong` và `lan` (hạn mức 100 triệu, nợ 30 ngày), `khongno` (hạn mức 0).
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
        $khoB = $this->warehouseOf($branchB);
        $npk = $this->makeProduct($owner, $branchA, $kg, $bao, 'NPK-16');
        $npkB = $this->makeProduct($owner, $branchB, $kg, $bao, 'NPK-B');
        $this->postStock($owner, $khoA, 'receipt', [['product_id' => $npk['id'], 'unit_id' => $kg->uuid, 'quantity' => 500, 'unit_cost' => 1000]])->assertCreated();
        $this->postStock($owner, $khoB, 'receipt', [['product_id' => $npkB['id'], 'unit_id' => $kg->uuid, 'quantity' => 500, 'unit_cost' => 1000]])->assertCreated();

        $customer = fn (array $extra) => $this->api($owner)->postJson('/api/v1/customers', array_merge(['branch_id' => $branchA->uuid, 'type' => 'individual'], $extra))->assertCreated()->json('data');

        return [
            'tenant' => $tenant, 'owner' => $owner, 'branchA' => $branchA, 'branchB' => $branchB, 'kg' => $kg, 'khoA' => $khoA, 'khoB' => $khoB, 'npk' => $npk, 'npkB' => $npkB,
            'nong' => $customer(['name' => 'Nguyễn Văn Nông', 'phone' => '0901000111', 'credit_limit' => 100000000, 'credit_days' => 30]),
            'lan' => $customer(['name' => 'Lê Thị Lan', 'credit_limit' => 100000000, 'credit_days' => 30]),
        ];
    }

    /** Bán chịu $amount đồng (1 Kg × đơn giá) cho khách, bán cách đây $daysAgo ngày (hạn trả = ngày bán + 30). $paid là số trả ngay. */
    private function debt(array $w, array $customer, int $amount, int $daysAgo = 0, int $paid = 0, ?object $by = null, bool $branchB = false): string
    {
        $product = $branchB ? $w['npkB'] : $w['npk'];
        $kho = $branchB ? $w['khoB'] : $w['khoA'];

        return $this->api($by ?? $w['owner'])->postJson('/api/v1/sales', [
            'warehouse_id' => $kho->uuid, 'customer_id' => $customer['id'], 'sold_on' => $this->today(-$daysAgo), 'paid_amount' => $paid,
            'lines' => [['product_id' => $product['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.id');
    }

    private function receivables(array $w, string $query = '', ?object $user = null): TestResponse
    {
        return $this->api($user ?? $w['owner'])->getJson('/api/v1/receivables'.$query);
    }

    private function collect(array $w, array $customer, array $body, ?object $user = null): TestResponse
    {
        return $this->api($user ?? $w['owner'])->postJson("/api/v1/receivables/{$customer['id']}/collect", array_merge(['method' => 'cash'], $body));
    }

    private function sale(array $w, string $id): array
    {
        return $this->api($w['owner'])->getJson("/api/v1/sales/{$id}")->json('data');
    }

    public function test_lists_debtors_biggest_first_with_the_amount_overdue_and_an_aging_summary(): void
    {
        $w = $this->world();
        $this->debt($w, $w['nong'], 200000, 0);               // chưa đến hạn
        $this->debt($w, $w['nong'], 300000, 40, 100000);      // còn nợ 200.000, quá hạn 10 ngày
        $this->debt($w, $w['lan'], 900000, 100);              // quá hạn 70 ngày
        $this->debt($w, $w['lan'], 50000, 0, 50000);          // đã trả đủ: không tính

        $response = $this->receivables($w)->assertOk();
        $response->assertJsonPath('summary.debtors', 2)->assertJsonPath('summary.owed', '1300000.00')
            ->assertJsonPath('summary.aging.current', '200000.00')->assertJsonPath('summary.aging.overdue_1_30', '200000.00')
            ->assertJsonPath('summary.aging.overdue_31_60', '0.00')->assertJsonPath('summary.aging.overdue_over_60', '900000.00')
            ->assertJsonPath('meta.total', 2);

        // Lan nợ nhiều hơn nên đứng đầu.
        $response->assertJsonPath('data.0.customer.name', 'Lê Thị Lan')->assertJsonPath('data.0.owed', '900000.00')->assertJsonPath('data.0.open_orders', 1)
            ->assertJsonPath('data.0.overdue_amount', '900000.00')->assertJsonPath('data.0.is_overdue', true)->assertJsonPath('data.0.days_overdue', 70)
            ->assertJsonPath('data.0.customer.credit_limit', '100000000.00');
        $response->assertJsonPath('data.1.customer.name', 'Nguyễn Văn Nông')->assertJsonPath('data.1.owed', '400000.00')->assertJsonPath('data.1.open_orders', 2)
            ->assertJsonPath('data.1.overdue_amount', '200000.00')->assertJsonPath('data.1.days_overdue', 10)->assertJsonPath('data.1.oldest_overdue_on', $this->today(-10));
    }

    public function test_filters_by_name_code_phone_and_overdue_only_and_the_summary_follows_the_filter(): void
    {
        $w = $this->world();
        $this->debt($w, $w['nong'], 200000, 0);
        $this->debt($w, $w['lan'], 900000, 100);

        $this->receivables($w, '?q=0901000')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Nguyễn Văn Nông')->assertJsonPath('summary.owed', '200000.00');
        $this->receivables($w, '?q='.urlencode('lê thị'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Lê Thị Lan');
        $this->receivables($w, '?q='.urlencode('%'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->receivables($w, '?overdue=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.name', 'Lê Thị Lan')->assertJsonPath('summary.debtors', 1);
        $this->receivables($w, '?per_page=1&page=2')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.customer.name', 'Nguyễn Văn Nông');
        $this->receivables($w, '?per_page=500')->assertUnprocessable();
        $this->receivables($w, '?overdue=maybe')->assertUnprocessable();
    }

    public function test_nobody_owes_means_an_empty_list_and_walk_in_sales_never_count(): void
    {
        $w = $this->world();
        $this->api($w['owner'])->postJson('/api/v1/sales', [
            'warehouse_id' => $w['khoA']->uuid, 'lines' => [['product_id' => $w['npk']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 1, 'unit_price' => 5000]],
        ])->assertCreated();

        $this->receivables($w)->assertOk()->assertJsonPath('data', [])->assertJsonPath('summary.debtors', 0)->assertJsonPath('summary.owed', '0.00')->assertJsonPath('summary.aging.current', '0.00');
    }

    public function test_a_collection_pays_the_oldest_due_invoice_first_and_every_invoice_gets_its_own_payment(): void
    {
        $w = $this->world();
        $newer = $this->debt($w, $w['nong'], 100000, 5);   // hạn +25
        $middle = $this->debt($w, $w['nong'], 300000, 20); // hạn +10
        $oldest = $this->debt($w, $w['nong'], 200000, 50); // hạn -20 (quá hạn); lập sau cùng vì khách quá hạn thì không mua chịu thêm được

        $r = $this->collect($w, $w['nong'], ['amount' => 350000, 'method' => 'bank_transfer', 'note' => 'Khách chuyển khoản', 'paid_on' => $this->today(-1)])->assertOk();
        $r->assertJsonPath('data.collected', '350000.00')->assertJsonPath('data.remaining', '250000.00')
            ->assertJsonPath('data.allocations.0.order.code', 'BH0003')->assertJsonPath('data.allocations.0.amount', '200000.00')->assertJsonPath('data.allocations.0.balance', '0.00')
            ->assertJsonPath('data.allocations.1.order.code', 'BH0002')->assertJsonPath('data.allocations.1.amount', '150000.00')->assertJsonPath('data.allocations.1.balance', '150000.00');
        $this->assertCount(2, $r->json('data.allocations'));

        $this->assertSame('paid', $this->sale($w, $oldest)['payment_status']);
        $this->assertSame('partial', $this->sale($w, $middle)['payment_status']);
        $this->assertSame('unpaid', $this->sale($w, $newer)['payment_status']);

        $payment = $this->sale($w, $middle)['payments'][0];
        $this->assertSame('150000.00', $payment['amount']);
        $this->assertSame('bank_transfer', $payment['method']);
        $this->assertSame('Khách chuyển khoản', $payment['note']);
        $this->assertSame($this->today(-1), $payment['paid_on']);

        $audit = $this->lastAudit(['module' => 'sales', 'action' => 'payment_recorded']);
        $this->assertTrue($audit->after['bulk']);

        // Một lần thu của phiếu nào hủy nhầm thì chỉ phiếu đó nợ lại, phiếu kia không đổi.
        $this->api($w['owner'])->postJson("/api/v1/sales/{$oldest}/payments/{$this->sale($w, $oldest)['payments'][0]['id']}/void", ['reason' => 'Nhầm'])->assertOk();
        $this->assertSame('unpaid', $this->sale($w, $oldest)['payment_status']);
        $this->assertSame('partial', $this->sale($w, $middle)['payment_status']);
        $this->receivables($w)->assertJsonPath('data.0.owed', '450000.00');
    }

    public function test_collecting_everything_clears_the_debt_and_more_than_the_debt_is_refused(): void
    {
        $w = $this->world();
        $this->debt($w, $w['nong'], 100000, 0);
        $this->debt($w, $w['nong'], 200000, 0);

        $over = $this->collect($w, $w['nong'], ['amount' => 300000.01])->assertUnprocessable();
        $this->assertStringContainsString('vượt quá số khách còn nợ', $over->json('errors.amount.0'));
        $this->assertSame(0, $this->platform(fn () => SalesPayment::where('amount', 300000.01)->count()));
        $this->receivables($w)->assertJsonPath('summary.owed', '300000.00');

        $this->collect($w, $w['nong'], ['amount' => 300000])->assertOk()->assertJsonPath('data.remaining', '0.00');
        $this->receivables($w)->assertJsonPath('data', [])->assertJsonPath('summary.owed', '0.00');
        $this->collect($w, $w['nong'], ['amount' => 1])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    public function test_the_amount_method_and_date_are_validated(): void
    {
        $w = $this->world();
        $this->debt($w, $w['nong'], 100000, 0);

        $this->collect($w, $w['nong'], ['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->collect($w, $w['nong'], ['amount' => -5])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->collect($w, $w['nong'], [])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->collect($w, $w['nong'], ['amount' => 100, 'method' => 'momo'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->collect($w, $w['nong'], ['amount' => 100, 'paid_on' => '2999-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['paid_on']);
        $this->api($w['owner'])->postJson('/api/v1/receivables/khong-phai-uuid/collect', ['amount' => 1, 'method' => 'cash'])->assertNotFound();
        $this->api($w['owner'])->postJson('/api/v1/receivables/'.fake()->uuid().'/collect', ['amount' => 1, 'method' => 'cash'])->assertNotFound();
    }

    public function test_a_manager_sees_and_collects_only_the_debt_of_their_own_branch(): void
    {
        $w = $this->world();
        $this->debt($w, $w['nong'], 100000, 0);                        // chi nhánh A
        $this->debt($w, $w['nong'], 400000, 0, 0, null, true);         // chi nhánh B, cùng một khách
        $managerA = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchA']);
        $managerB = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchB']);

        $this->receivables($w)->assertJsonPath('data.0.owed', '500000.00')->assertJsonPath('data.0.open_orders', 2);
        $this->receivables($w, '', $managerA)->assertJsonPath('data.0.owed', '100000.00')->assertJsonPath('data.0.open_orders', 1)->assertJsonPath('summary.owed', '100000.00');
        $this->receivables($w, '', $managerB)->assertJsonPath('data.0.owed', '400000.00');

        // Quản lý chi nhánh A chỉ thu được phần nợ của A, dù có khách nợ thêm ở B.
        $this->collect($w, $w['nong'], ['amount' => 100001], $managerA)->assertUnprocessable();
        $this->collect($w, $w['nong'], ['amount' => 100000], $managerA)->assertOk()->assertJsonPath('data.remaining', '0.00');
        $this->receivables($w, '', $managerA)->assertJsonPath('data', []);
        $this->receivables($w)->assertJsonPath('data.0.owed', '400000.00');
        $this->assertSame(1, $this->platform(fn () => SalesOrder::whereColumn('paid_amount', '<', 'total')->count()));

        // Chi nhánh khác không có nợ của khách này thì không thu được gì.
        $this->collect($w, $w['lan'], ['amount' => 1], $managerA)->assertUnprocessable();
    }

    public function test_a_salesperson_collects_only_on_their_own_sales_and_other_roles_cannot_collect(): void
    {
        $w = $this->world();
        $seller = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $other = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $this->debt($w, $w['nong'], 100000, 0, 0, $seller);
        $this->debt($w, $w['nong'], 400000, 0, 0, $other);

        // Cùng chi nhánh thì xem hết nhưng chỉ thu được phiếu của chính mình (phạm vi `own`).
        $this->receivables($w, '', $seller)->assertJsonPath('data.0.owed', '500000.00');
        $this->collect($w, $w['nong'], ['amount' => 100001], $seller)->assertUnprocessable();
        $this->collect($w, $w['nong'], ['amount' => 100000], $seller)->assertOk();
        $this->receivables($w)->assertJsonPath('data.0.owed', '400000.00');

        // Kế toán, thủ kho xem được; không thu tiền. Nhân viên giao hàng chưa vào được.
        foreach (['accountant', 'warehouse'] as $role) {
            $user = $this->makeUserIn($w['tenant'], [$role], $w['branchA']);
            $this->receivables($w, '', $user)->assertOk()->assertJsonPath('data.0.owed', '400000.00');
            $this->collect($w, $w['nong'], ['amount' => 1], $user)->assertForbidden();
        }
        $delivery = $this->makeUserIn($w['tenant'], ['delivery'], $w['branchA']);
        $this->receivables($w, '', $delivery)->assertForbidden();
        $this->collect($w, $w['nong'], ['amount' => 1], $delivery)->assertForbidden();
    }

    public function test_requires_login(): void
    {
        $this->spa()->getJson('/api/v1/receivables')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/receivables/'.fake()->uuid().'/collect', ['amount' => 1, 'method' => 'cash'])->assertUnauthorized();
    }
}
