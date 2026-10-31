<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\TestCase;

/** Tra cứu tồn kho: tổng quan theo sản phẩm, số dư theo lô, thẻ kho và các con số cảnh báo (sắp hết hàng, lô sắp / đã hết hạn). */
class StockQueryTest extends TestCase
{
    use CreatesTenants, MakesCatalog, MakesStock, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    private function today(int $plusDays = 0): string
    {
        return Carbon::now(config('inventory.timezone'))->addDays($plusDays)->toDateString();
    }

    /**
     * Một cửa hàng có: phân NPK (không theo dõi lô, tồn tối thiểu 100 Kg, đang có 80 Kg → sắp hết), phân Urê (không có tồn),
     * thuốc A (tồn 10 Kg ở lô sắp hết hạn + 5 Kg ở lô đã hết hạn), thuốc B (lô còn hạn lâu).
     *
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $kho = $this->warehouseOf($branch);

        $npk = $this->makeProduct($owner, $branch, $kg, $bao, 'NPK-16', false, ['min_stock' => 100]);
        $ure = $this->makeProduct($owner, $branch, $kg, $bao, 'URE-01');
        $drugA = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-A', true);
        $drugB = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-B', true);

        $line = fn (array $p, $qty, array $extra = []) => ['product_id' => $p['id'], 'unit_id' => $kg->uuid, 'quantity' => $qty, 'unit_cost' => 10000] + $extra;
        $this->postStock($owner, $kho, 'receipt', [$line($npk, 80)])->assertCreated();
        $this->postStock($owner, $kho, 'receipt', [
            $line($drugA, 10, ['batch_no' => 'A-SAP', 'expires_at' => $this->today(30)]),
            $line($drugA, 5, ['batch_no' => 'A-CU', 'expires_at' => $this->today(-5)]),
            $line($drugB, 7, ['batch_no' => 'B-1', 'expires_at' => $this->today(300)]),
        ])->assertCreated();

        return compact('tenant', 'owner', 'branch', 'kg', 'bao', 'kho', 'npk', 'ure', 'drugA', 'drugB');
    }

    public function test_the_overview_lists_every_product_with_totals_expiry_and_the_low_stock_flag(): void
    {
        ['owner' => $owner] = $this->shop();

        $rows = collect($this->api($owner)->getJson('/api/v1/stock/products')->assertOk()->json('data'))->keyBy('sku');
        $this->assertSame(['NPK-16', 'TS-A', 'TS-B', 'URE-01'], $rows->keys()->sort()->values()->all());

        $this->assertSame('80.000', $rows['NPK-16']['total_qty']);
        $this->assertSame('100.000', $rows['NPK-16']['min_stock']);
        $this->assertTrue($rows['NPK-16']['is_low']);
        $this->assertSame('Kg', $rows['NPK-16']['base_unit']['name']);
        $this->assertNull($rows['NPK-16']['next_expiry']);

        $this->assertSame('0.000', $rows['URE-01']['total_qty']);
        $this->assertFalse($rows['URE-01']['is_low'], 'không đặt mức tối thiểu thì không báo sắp hết');

        $this->assertSame('15.000', $rows['TS-A']['total_qty']);
        $this->assertSame('5.000', $rows['TS-A']['expired_qty']);
        $this->assertSame('10.000', $rows['TS-A']['expiring_qty']);
        $this->assertSame($this->today(-5), $rows['TS-A']['next_expiry']);
        $this->assertSame('0.000', $rows['TS-B']['expired_qty']);
        $this->assertSame('0.000', $rows['TS-B']['expiring_qty']);
        $this->assertSame('800000.00', $rows['NPK-16']['value']);
    }

    public function test_the_overview_filters(): void
    {
        ['owner' => $owner, 'kho' => $kho] = $this->shop();
        $skus = fn (string $query) => collect($this->api($owner)->getJson("/api/v1/stock/products{$query}")->assertOk()->json('data'))->pluck('sku')->sort()->values()->all();

        $this->assertSame(['NPK-16', 'TS-A', 'TS-B'], $skus('?stock=in'));
        $this->assertSame(['URE-01'], $skus('?stock=out'));
        $this->assertSame(['NPK-16'], $skus('?stock=low'));
        $this->assertSame(['TS-A'], $skus('?expiry=expired'));
        $this->assertSame(['TS-A'], $skus('?expiry=soon'));
        $this->assertSame(['TS-A', 'TS-B'], $skus('?type=pesticide'));
        $this->assertSame(['NPK-16'], $skus('?q=npk'));
        $this->assertSame(['URE-01'], $skus('?q=ure'));
        $this->assertSame(['TS-A', 'TS-B'], $skus('?q=thuốc'));
        // "Sắp hết hạn" theo số ngày tự chọn: trong 365 ngày thì lô của thuốc B (300 ngày nữa) cũng tính.
        $this->assertSame(['TS-A', 'TS-B'], $skus('?expiry=soon&days=365'));
        $this->assertSame(['NPK-16', 'TS-A', 'TS-B'], $skus("?warehouse={$kho->uuid}&stock=in"));
        $this->assertSame([], $skus('?warehouse=00000000-0000-4000-8000-000000000000'));
        $this->api($owner)->getJson('/api/v1/stock/products?stock=xyz')->assertUnprocessable();
        $this->api($owner)->getJson('/api/v1/stock/products?expiry=xyz')->assertUnprocessable();
    }

    public function test_the_barcode_finds_the_product_too(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $product = $this->makeProduct($owner, $branch, $kg, $bao);
        $this->api($owner)->patchJson("/api/v1/products/{$product['id']}", ['units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1, 'barcode' => '8930000012345'],
        ]])->assertOk();

        $this->assertCount(1, $this->api($owner)->getJson('/api/v1/stock/products?q=8930000012')->json('data'));
        $this->assertCount(0, $this->api($owner)->getJson('/api/v1/stock/products?q=999999')->json('data'));
    }

    public function test_the_summary_counts_the_alerts_and_adds_up_the_stock_value(): void
    {
        ['owner' => $owner] = $this->shop();

        $data = $this->api($owner)->getJson('/api/v1/stock/summary')->assertOk()->json('data');
        $this->assertSame(1, $data['low_products']);
        $this->assertSame(1, $data['expired_batches']);
        $this->assertSame(1, $data['expiring_batches']);
        $this->assertSame(60, $data['expiry_warning_days']);
        // (80 + 10 + 5 + 7) × 10.000
        $this->assertSame('1020000.00', $data['stock_value']);

        $this->assertSame(2, $this->api($owner)->getJson('/api/v1/stock/summary?days=365')->json('data.expiring_batches'), 'trong 365 ngày thì lô của thuốc B (300 ngày nữa) cũng tính');
    }

    public function test_a_batch_that_is_empty_no_longer_raises_an_expiry_alert(): void
    {
        ['owner' => $owner, 'kho' => $kho, 'kg' => $kg, 'drugA' => $drugA] = $this->shop();
        $expired = collect($this->api($owner)->getJson('/api/v1/stock/balances?expiry=expired')->json('data'))->firstWhere('batch.batch_no', 'A-CU');
        $this->assertNotNull($expired);

        $this->postStock($owner, $kho, 'issue', [['product_id' => $drugA['id'], 'unit_id' => $kg->uuid, 'quantity' => 5, 'batch_id' => $expired['batch']['id']]], ['reason' => 'expired'])->assertCreated();

        $this->assertSame(0, $this->api($owner)->getJson('/api/v1/stock/summary')->json('data.expired_batches'));
        $this->assertSame([], $this->api($owner)->getJson('/api/v1/stock/balances?expiry=expired')->json('data'));
    }

    public function test_balances_per_batch_are_listed_with_days_to_expiry_soonest_first(): void
    {
        ['owner' => $owner, 'drugA' => $drugA] = $this->shop();

        $rows = $this->api($owner)->getJson("/api/v1/stock/balances?product={$drugA['id']}")->assertOk()->json('data');
        $this->assertSame(['A-CU', 'A-SAP'], array_column(array_column($rows, 'batch'), 'batch_no'));
        $this->assertTrue($rows[0]['expired']);
        $this->assertSame(-5, $rows[0]['days_to_expiry']);
        $this->assertFalse($rows[1]['expired']);
        $this->assertSame(30, $rows[1]['days_to_expiry']);
        $this->assertSame('10.000', $rows[1]['quantity']);
        $this->assertSame('Kg', $rows[1]['product']['base_unit']);
        $this->assertSame('KHO-01', $rows[1]['warehouse']['code']);

        $this->assertSame(['A-SAP'], array_column(array_column($this->api($owner)->getJson('/api/v1/stock/balances?expiry=soon')->json('data'), 'batch'), 'batch_no'));
        // Dòng không có lô (sản phẩm không theo dõi lô) vẫn có số dư với batch = null.
        $plain = collect($this->api($owner)->getJson('/api/v1/stock/balances?q=NPK')->json('data'))->first();
        $this->assertNull($plain['batch']);
        $this->assertNull($plain['days_to_expiry']);
    }

    public function test_the_stock_card_lists_the_movements_newest_first_with_filters(): void
    {
        ['owner' => $owner, 'kho' => $kho, 'bao' => $bao, 'npk' => $npk] = $this->shop();
        $this->postStock($owner, $kho, 'issue', [['product_id' => $npk['id'], 'unit_id' => $bao->uuid, 'quantity' => 1]], ['reason' => 'damaged'])->assertCreated();

        $rows = $this->api($owner)->getJson("/api/v1/stock/movements?product={$npk['id']}")->assertOk()->json('data');
        $this->assertSame(['issue', 'receipt'], array_column($rows, 'type'));
        $this->assertSame('-50.000', $rows[0]['quantity_delta']);
        $this->assertSame('30.000', $rows[0]['balance_after']);
        $this->assertSame('80.000', $rows[1]['balance_after']);
        $this->assertSame('PX0001', $rows[0]['document']['code']);
        $this->assertSame('damaged', $rows[0]['document']['reason']);
        $this->assertSame('Bao', $rows[0]['entered']['unit']);
        $this->assertSame('NPK-16', $rows[0]['product']['sku']);

        $this->assertCount(1, $this->api($owner)->getJson("/api/v1/stock/movements?product={$npk['id']}&type=issue")->json('data'));
        $this->assertCount(0, $this->api($owner)->getJson("/api/v1/stock/movements?product={$npk['id']}&to=".$this->today(-1))->json('data'));
        $this->assertCount(2, $this->api($owner)->getJson("/api/v1/stock/movements?warehouse={$kho->uuid}&product={$npk['id']}")->json('data'));
    }

    public function test_the_minimum_stock_is_set_through_the_product_api(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $product = $this->makeProduct($owner, $branch, $kg, $bao, 'NPK-16', false, ['min_stock' => 12.5]);
        $this->assertSame('12.500', $product['min_stock']);

        $this->api($owner)->patchJson("/api/v1/products/{$product['id']}", ['min_stock' => 20])->assertOk()->assertJsonPath('data.min_stock', '20.000');
        $this->api($owner)->patchJson("/api/v1/products/{$product['id']}", ['min_stock' => null])->assertOk()->assertJsonPath('data.min_stock', null);
        $this->api($owner)->patchJson("/api/v1/products/{$product['id']}", ['min_stock' => -1])->assertUnprocessable()->assertJsonValidationErrors(['min_stock']);
        $this->api($owner)->patchJson("/api/v1/products/{$product['id']}", ['min_stock' => 1.2345])->assertUnprocessable()->assertJsonValidationErrors(['min_stock']);
    }

    public function test_the_numbers_stay_inside_the_viewers_branches(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'kg' => $kg, 'bao' => $bao] = $this->shop();
        $branchB = $this->makeBranch($tenant);
        $khoB = $this->warehouseOf($branchB);
        $productB = $this->makeProduct($owner, $branchB, $kg, $bao, 'KHAC-1', false, ['min_stock' => 50]);
        $this->postStock($owner, $khoB, 'receipt', [['product_id' => $productB['id'], 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $this->platform(fn () => Branch::where('tenant_id', $tenant->id)->orderBy('id')->firstOrFail()));

        $this->assertSame(1, $this->api($manager)->getJson('/api/v1/stock/summary')->json('data.low_products'), 'chỉ đếm NPK-16 của chi nhánh mình, không đếm KHAC-1 ở chi nhánh B');
        $this->assertSame(2, $this->api($owner)->getJson('/api/v1/stock/summary')->json('data.low_products'));
        $this->assertNotContains('KHAC-1', array_column($this->api($manager)->getJson('/api/v1/stock/products')->json('data'), 'sku'));
        $this->assertNotContains('KHAC-1', array_column(array_column($this->api($manager)->getJson('/api/v1/stock/balances')->json('data'), 'product'), 'sku'));
    }
}
