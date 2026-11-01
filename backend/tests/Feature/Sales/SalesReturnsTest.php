<?php

namespace Tests\Feature\Sales;

use App\Models\Batch;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\Warehouse;
use Carbon\Carbon;
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
 * Trả hàng: khách trả lại hàng của một phiếu bán, một bước. Hàng nhập lại đúng kho (và đúng lô) theo giá vốn lúc bán; giá trị hàng trả (đã chia phần bớt tiền của phiếu) trừ vào nợ
 * của phiếu trước, phần vượt hoàn lại cho khách; phiếu bán gốc không sửa, chỉ `returned_amount` tăng và `paid_amount` giảm đúng số đã hoàn.
 */
class SalesReturnsTest extends TestCase
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
     * Hai chi nhánh A (kho khoA) và B; NPK-16 (Kg, Bao = 50 Kg, đã nhập 100 Kg giá vốn 10.000/Kg) và thuốc TS-01 (theo dõi lô) ở A; khách `nong` (hạn mức 1.000.000, nợ 30 ngày) do A phụ trách.
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

        return [
            'tenant' => $tenant, 'owner' => $owner, 'branchA' => $branchA, 'branchB' => $branchB, 'kg' => $kg, 'khoA' => $khoA,
            'npk' => $npk, 'drug' => $this->makeProduct($owner, $branchA, $kg, $bao, 'TS-01', true),
            'nong' => $this->api($owner)->postJson('/api/v1/customers', ['branch_id' => $branchA->uuid, 'type' => 'individual', 'name' => 'Nguyễn Văn Nông', 'credit_limit' => 1000000, 'credit_days' => 30])->assertCreated()->json('data'),
        ];
    }

    private function sell(array $w, int|float $quantity, int|float $price, array $extra = [], ?object $user = null, ?array $product = null): TestResponse
    {
        $line = ['product_id' => ($product ?? $w['npk'])['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => $quantity, 'unit_price' => $price];

        return $this->api($user ?? $w['owner'])->postJson('/api/v1/sales', array_merge(['warehouse_id' => $w['khoA']->uuid, 'lines' => [$line]], $extra));
    }

    /** @return list<array<string, mixed>> */
    private function linesOf(array $w, string $orderId): array
    {
        return $this->api($w['owner'])->getJson("/api/v1/sales/{$orderId}")->json('data.lines');
    }

    /** Trả hàng: $quantities = số lượng trả theo thứ tự dòng của phiếu (0 = không trả dòng đó). */
    private function giveBack(array $w, string $orderId, array $quantities, array $extra = [], ?object $user = null): TestResponse
    {
        $lines = [];

        foreach ($this->linesOf($w, $orderId) as $i => $line) {
            if (($quantities[$i] ?? 0) > 0) {
                $lines[] = ['line_id' => $line['id'], 'quantity' => $quantities[$i]];
            }
        }

        return $this->api($user ?? $w['owner'])->postJson("/api/v1/sales/{$orderId}/returns", array_merge(['lines' => $lines], $extra));
    }

    private function stock(Warehouse $kho, array $product, ?string $batchNo = null): float
    {
        $id = $this->platform(fn () => Product::where('uuid', $product['id'])->value('id'));

        return (float) $this->platform(fn () => StockBalance::where('warehouse_id', $kho->id)->where('product_id', $id)
            ->when($batchNo, fn ($q) => $q->whereIn('batch_id', Batch::where('batch_no', $batchNo)->select('id')))->sum('quantity'));
    }

    private function counts(): array
    {
        return $this->platform(fn () => [SalesReturn::count(), StockDocument::count()]);
    }

    public function test_returning_part_of_a_paid_sale_puts_the_goods_back_and_refunds_the_discounted_value(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 15500, ['discount' => 5000])->assertCreated()->assertJsonPath('data.total', '150000.00')->json('data.id'); // trả đủ 150.000, tiền hàng 155.000

        $response = $this->giveBack($w, $id, [4], ['note' => ' Khách mua nhầm loại ', 'returned_on' => $this->today(), 'refund_method' => 'bank_transfer'])->assertCreated();

        // 4 Kg × 15.500 = 62.000 theo giá bán, chia phần bớt tiền (150.000 / 155.000) = 60.000 hoàn lại cho khách.
        $response->assertJsonPath('data.returned_amount', '60000.00')->assertJsonPath('data.net_total', '90000.00')->assertJsonPath('data.paid_amount', '90000.00')
            ->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.total', '150000.00')
            ->assertJsonPath('data.returns.0.code', 'TH0001')->assertJsonPath('data.returns.0.note', 'Khách mua nhầm loại')->assertJsonPath('data.returns.0.total', '60000.00')
            ->assertJsonPath('data.returns.0.debt_reduction', '0.00')->assertJsonPath('data.returns.0.refund_amount', '60000.00')->assertJsonPath('data.returns.0.refund_method', 'bank_transfer')
            ->assertJsonPath('data.returns.0.created_by.name', $w['owner']->name)->assertJsonPath('data.returns.0.stock_document.code', 'PN0002')
            ->assertJsonPath('data.returns.0.lines.0.product.sku', 'NPK-16')->assertJsonPath('data.returns.0.lines.0.quantity', '4.000')->assertJsonPath('data.returns.0.lines.0.line_total', '62000.00')
            ->assertJsonPath('data.lines.0.returned_quantity', '4.000');

        // Hàng nhập lại kho với giá vốn lúc bán (10.000/Kg), nên giá vốn bình quân không đổi.
        $this->assertSame(94.0, $this->stock($w['khoA'], $w['npk']));
        $document = $this->platform(fn () => StockDocument::where('code', 'PN0002')->firstOrFail());
        $this->assertSame(['receipt', 'sale_return'], [$document->type, $document->reason]);
        $this->assertStringContainsString('TH0001', (string) $document->note);
        $this->assertSame('10000.0000', $this->platform(fn () => (string) DB::table('product_costs')->value('avg_cost')));
        $this->assertSame('10000.0000', $this->platform(fn () => (string) DB::table('stock_movements')->where('document_id', $document->id)->value('unit_cost')));

        $audit = $this->lastAudit(['module' => 'sales', 'action' => 'return_created']);
        $this->assertSame(['TH0001', 'BH0001', '60000.00'], [$audit->after['code'], $audit->after['order'], $audit->after['refund_amount']]);

        // Trả nốt 6 Kg còn lại: lấy đúng phần còn lại của phiếu, khách được hoàn hết.
        $this->giveBack($w, $id, [6])->assertCreated()->assertJsonPath('data.returned_amount', '150000.00')->assertJsonPath('data.net_total', '0.00')
            ->assertJsonPath('data.paid_amount', '0.00')->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.returns.1.refund_amount', '90000.00')
            ->assertJsonPath('data.returns.1.refund_method', 'cash');
        $this->assertSame(100.0, $this->stock($w['khoA'], $w['npk']));

        // Hết hàng để trả.
        $this->giveBack($w, $id, [1])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    }

    public function test_the_value_of_the_goods_is_split_without_losing_a_cent_when_the_discount_makes_it_uneven(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 3, 10000, ['discount' => 1000])->assertCreated()->assertJsonPath('data.total', '29000.00')->json('data.id');

        $this->giveBack($w, $id, [1])->assertCreated()->assertJsonPath('data.returns.0.total', '9666.67');
        $this->giveBack($w, $id, [1])->assertCreated()->assertJsonPath('data.returns.1.total', '9666.67');
        $this->giveBack($w, $id, [1])->assertCreated()->assertJsonPath('data.returns.2.total', '9666.66')->assertJsonPath('data.returned_amount', '29000.00')->assertJsonPath('data.paid_amount', '0.00');
    }

    public function test_a_return_first_reduces_what_the_customer_still_owes_and_only_then_refunds(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 50000, ['customer_id' => $w['nong']['id'], 'paid_amount' => 100000])->assertCreated()->assertJsonPath('data.balance', '400000.00')->json('data.id');

        // 300.000 hàng trả lại < 400.000 còn nợ: chỉ trừ nợ, không hoàn tiền (kể cả khi gửi hình thức hoàn tiền).
        $this->giveBack($w, $id, [6], ['refund_method' => 'bank_transfer'])->assertCreated()
            ->assertJsonPath('data.balance', '100000.00')->assertJsonPath('data.paid_amount', '100000.00')->assertJsonPath('data.net_total', '200000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.returns.0.debt_reduction', '300000.00')->assertJsonPath('data.returns.0.refund_amount', '0.00')->assertJsonPath('data.returns.0.refund_method', null);
        $this->api($w['owner'])->getJson('/api/v1/receivables')->assertJsonPath('data.0.owed', '100000.00')->assertJsonPath('summary.owed', '100000.00');

        // Trả nốt 200.000: nợ còn 100.000 được xóa, phần 100.000 khách đã trả được hoàn lại.
        $this->giveBack($w, $id, [4], ['refund_method' => 'bank_transfer'])->assertCreated()
            ->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.paid_amount', '0.00')->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.returns.1.debt_reduction', '100000.00')->assertJsonPath('data.returns.1.refund_amount', '100000.00')->assertJsonPath('data.returns.1.refund_method', 'bank_transfer');
        $this->api($w['owner'])->getJson('/api/v1/receivables')->assertJsonPath('data', [])->assertJsonPath('summary.owed', '0.00');
    }

    public function test_a_walk_in_customer_is_refunded_in_full(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 15500)->assertCreated()->json('data.id');

        $this->giveBack($w, $id, [3])->assertCreated()->assertJsonPath('data.customer', null)->assertJsonPath('data.returns.0.refund_amount', '46500.00')
            ->assertJsonPath('data.paid_amount', '108500.00')->assertJsonPath('data.net_total', '108500.00')->assertJsonPath('data.balance', '0.00');
        $this->assertSame(93.0, $this->stock($w['khoA'], $w['npk']));
    }

    public function test_goods_go_back_into_their_original_batches(): void
    {
        $w = $this->world();
        $lot = fn (string $no, string $expires, int $qty) => ['product_id' => $w['drug']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => $qty, 'unit_cost' => 50000, 'batch_no' => $no, 'expires_at' => $expires];
        $this->postStock($w['owner'], $w['khoA'], 'receipt', [$lot('L-XA', $this->today(730), 10), $lot('L-GAN', $this->today(365), 5)])->assertCreated();

        $id = $this->sell($w, 12, 100000, [], null, $w['drug'])->assertCreated()->json('data.id'); // 5 Kg lô gần + 7 Kg lô xa
        $this->assertSame([0.0, 3.0], [$this->stock($w['khoA'], $w['drug'], 'L-GAN'), $this->stock($w['khoA'], $w['drug'], 'L-XA')]);
        $this->assertCount(2, $this->linesOf($w, $id));

        // Trả 2 Kg của dòng lô gần và 3 Kg của dòng lô xa.
        $this->giveBack($w, $id, [2, 3])->assertCreated()->assertJsonPath('data.lines.0.returned_quantity', '2.000')->assertJsonPath('data.lines.1.returned_quantity', '3.000')
            ->assertJsonPath('data.returned_amount', '500000.00');
        $this->assertSame([2.0, 6.0], [$this->stock($w['khoA'], $w['drug'], 'L-GAN'), $this->stock($w['khoA'], $w['drug'], 'L-XA')]);

        $this->giveBack($w, $id, [3, 4])->assertCreated()->assertJsonPath('data.net_total', '0.00');
        $this->assertSame([5.0, 10.0], [$this->stock($w['khoA'], $w['drug'], 'L-GAN'), $this->stock($w['khoA'], $w['drug'], 'L-XA')]);
    }

    public function test_a_collection_already_refunded_cannot_be_voided_but_an_earlier_one_can(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 50000, ['customer_id' => $w['nong']['id'], 'paid_amount' => 100000])->assertCreated()->json('data.id');
        $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments", ['amount' => 400000, 'method' => 'cash'])->assertOk();
        $this->giveBack($w, $id, [4])->assertCreated()->assertJsonPath('data.paid_amount', '300000.00'); // hoàn 200.000

        $payments = $this->api($w['owner'])->getJson("/api/v1/sales/{$id}")->json('data.payments');
        $void = fn (string $payment) => $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/payments/{$payment}/void", ['reason' => 'Nhầm']);

        $void($payments[1]['id'])->assertUnprocessable()->assertJsonValidationErrors(['payment']); // 300.000 − 400.000 < 0
        $void($payments[0]['id'])->assertOk()->assertJsonPath('data.paid_amount', '200000.00')->assertJsonPath('data.balance', '100000.00');
    }

    public function test_returning_the_overdue_goods_lets_the_customer_buy_on_credit_again(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 1, 10000, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0, 'sold_on' => $this->today(-40)])->assertCreated()->assertJsonPath('data.is_overdue', true)->json('data.id');
        $this->sell($w, 1, 10000, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertUnprocessable();

        $this->giveBack($w, $id, [1])->assertCreated()->assertJsonPath('data.is_overdue', false)->assertJsonPath('data.balance', '0.00');
        $this->sell($w, 1, 10000, ['customer_id' => $w['nong']['id'], 'paid_amount' => 0])->assertCreated();
    }

    public function test_bad_returns_are_refused_and_nothing_is_written(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 15500, ['sold_on' => $this->today(-5)])->assertCreated()->json('data.id');
        $line = $this->linesOf($w, $id)[0]['id'];
        $before = $this->counts();
        $post = fn (array $body) => $this->api($w['owner'])->postJson("/api/v1/sales/{$id}/returns", $body);

        $over = $post(['lines' => [['line_id' => $line, 'quantity' => 10.001]]])->assertUnprocessable();
        $this->assertStringContainsString('Chỉ trả lại được tối đa 10 Kg', $over->json('errors')['lines.0.quantity'][0]);
        $post(['lines' => [['line_id' => $line, 'quantity' => 3], ['line_id' => $line, 'quantity' => 3]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.line_id']);
        $post(['lines' => [['line_id' => fake()->uuid(), 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.line_id']);
        $post(['lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $post([])->assertUnprocessable()->assertJsonValidationErrors(['lines']);
        $post(['lines' => [['line_id' => $line, 'quantity' => 0]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $post(['lines' => [['line_id' => $line, 'quantity' => 1.0005]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
        $post(['lines' => [['line_id' => 'không-phải-uuid', 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.line_id']);
        $ok = ['lines' => [['line_id' => $line, 'quantity' => 1]]];
        $post($ok + ['returned_on' => $this->today(-10)])->assertUnprocessable()->assertJsonValidationErrors(['returned_on']); // trước ngày bán
        $post($ok + ['returned_on' => $this->today(1)])->assertUnprocessable()->assertJsonValidationErrors(['returned_on']);
        $post($ok + ['refund_method' => 'momo'])->assertUnprocessable()->assertJsonValidationErrors(['refund_method']);

        $this->assertSame($before, $this->counts());
        $this->assertSame(90.0, $this->stock($w['khoA'], $w['npk']));
        $this->api($w['owner'])->postJson('/api/v1/sales/khong-phai-uuid/returns', $ok)->assertNotFound();
        $this->api($w['owner'])->postJson('/api/v1/sales/'.fake()->uuid().'/returns', $ok)->assertNotFound();
    }

    public function test_a_line_of_another_sale_cannot_be_returned_here(): void
    {
        $w = $this->world();
        $first = $this->sell($w, 5, 15500)->assertCreated()->json('data.id');
        $second = $this->sell($w, 5, 15500)->assertCreated()->json('data.id');
        $foreign = $this->linesOf($w, $second)[0]['id'];

        $this->api($w['owner'])->postJson("/api/v1/sales/{$first}/returns", ['lines' => [['line_id' => $foreign, 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.line_id']);
    }

    public function test_who_may_take_goods_back(): void
    {
        $w = $this->world();
        $seller = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $other = $this->makeUserIn($w['tenant'], ['sales'], $w['branchA']);
        $manager = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchA']);
        $managerB = $this->makeUserIn($w['tenant'], ['branch_manager'], $w['branchB']);
        $mine = $this->sell($w, 10, 15500, [], $seller)->assertCreated()->json('data.id');

        // Nhân viên bán hàng chỉ nhận lại hàng của phiếu do mình lập; quản lý chi nhánh nhận được mọi phiếu của chi nhánh mình.
        $this->giveBack($w, $mine, [1], [], $other)->assertForbidden();
        foreach (['accountant', 'warehouse', 'delivery'] as $role) {
            $this->giveBack($w, $mine, [1], [], $this->makeUserIn($w['tenant'], [$role], $w['branchA']))->assertForbidden();
        }
        $this->giveBack($w, $mine, [1], [], $managerB)->assertForbidden();
        $this->assertSame(0, $this->counts()[0]);

        $this->giveBack($w, $mine, [1], [], $seller)->assertCreated();
        $this->giveBack($w, $mine, [1], [], $manager)->assertCreated();
    }

    public function test_the_stock_receipt_points_back_to_its_return_and_the_reason_cannot_be_used_by_hand(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 15500)->assertCreated()->json('data.id');
        $this->giveBack($w, $id, [2])->assertCreated();

        $documents = $this->api($w['owner'])->getJson('/api/v1/stock/documents')->assertOk()->json('data');
        $this->assertSame(['TH0001', 'BH0001', 'sale_return'], [$documents[0]['sale_return']['code'] ?? null, $documents[0]['sale_return']['sale']['code'] ?? null, $documents[0]['reason']]);
        $this->assertNull($documents[1]['sale_return'] ?? null);

        // Gài khóa nội bộ khi nhập kho tay không tạo ra phiếu "khách trả hàng".
        $this->postStock($w['owner'], $w['khoA'], 'receipt', [['product_id' => $w['npk']['id'], 'unit_id' => $w['kg']->uuid, 'quantity' => 1, 'unit_cost' => 1]], ['reason' => 'sale_return', 'system_reason' => 'sale_return'])
            ->assertCreated()->assertJsonPath('data.reason', null);
    }

    public function test_returns_survive_a_backup_and_restore(): void
    {
        $w = $this->world();
        $id = $this->sell($w, 10, 15500, ['customer_id' => $w['nong']['id'], 'paid_amount' => 50000])->assertCreated()->json('data.id');
        $this->giveBack($w, $id, [3])->assertCreated();
        $snapshot = fn () => $this->platform(fn () => [
            DB::table('sales_orders')->orderBy('id')->get(['id', 'code', 'total', 'returned_amount', 'paid_amount'])->map(fn ($r) => (array) $r)->all(),
            DB::table('sales_order_lines')->orderBy('id')->get(['id', 'uuid', 'quantity'])->map(fn ($r) => (array) $r)->all(),
            DB::table('sales_returns')->orderBy('id')->get(['id', 'uuid', 'code', 'total', 'debt_reduction', 'refund_amount', 'stock_document_id'])->map(fn ($r) => (array) $r)->all(),
            DB::table('sales_return_lines')->orderBy('id')->get(['id', 'return_id', 'order_line_id', 'quantity', 'line_total'])->map(fn ($r) => (array) $r)->all(),
        ]);
        $before = $snapshot();

        $backup = $this->adminBackup($w['tenant']);
        $this->assertSame(1, $backup->row_counts['sales_returns']);
        $this->assertSame(1, $backup->row_counts['sales_return_lines']);

        $this->giveBack($w, $id, [2])->assertCreated();
        $this->adminRestore($backup, $w['tenant']->name)->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $snapshot());
        $this->giveBack($w, $id, [2])->assertCreated()->assertJsonPath('data.returns.1.code', 'TH0002');
        $this->assertSame(1, $this->platform(fn () => SalesOrder::count()));
    }
}
