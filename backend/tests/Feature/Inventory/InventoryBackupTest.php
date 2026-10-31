<?php

namespace Tests\Feature\Inventory;

use App\Models\Batch;
use App\Models\ProductCost;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\TestCase;

/** Kho nằm trong sao lưu / khôi phục theo doanh nghiệp, kể cả sổ kho bất biến (chỉ được xóa trong chính lần khôi phục). */
class InventoryBackupTest extends TestCase
{
    use AdministersBackups, CreatesTenants, MakesCatalog, MakesStock, RefreshDatabase;

    private const TABLES = ['warehouses', 'batches', 'product_costs', 'stock_transfers', 'stock_documents', 'stock_movements', 'stock_balances'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    public function test_the_tables_are_registered_for_backup_with_parents_before_children(): void
    {
        $tables = config('backup.tenant_tables');
        $pos = fn (string $t) => array_search($t, $tables, true);

        foreach (self::TABLES as $table) {
            $this->assertNotFalse($pos($table), "{$table} chưa nằm trong backup.tenant_tables");
            $this->assertTrue(Schema::hasColumns($table, ['id', 'tenant_id']));
        }

        // Cha trước con: kho/lô/sản phẩm trước phiếu, phiếu trước sổ, sổ và lô trước số dư.
        $this->assertLessThan($pos('stock_documents'), $pos('warehouses'));
        $this->assertLessThan($pos('batches'), $pos('products'));
        $this->assertLessThan($pos('product_costs'), $pos('products'));
        $this->assertLessThan($pos('stock_documents'), $pos('stock_transfers'), 'phiếu kho tham chiếu phiếu chuyển');
        $this->assertLessThan($pos('stock_transfers'), $pos('warehouses'));
        $this->assertLessThan($pos('stock_movements'), $pos('stock_documents'));
        $this->assertLessThan($pos('stock_movements'), $pos('batches'));
        $this->assertLessThan($pos('stock_balances'), $pos('batches'));
        $this->assertLessThan($pos('stock_balances'), $pos('warehouses'));
    }

    public function test_restoring_brings_back_the_warehouses_ledger_balances_and_costs_exactly(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $kho = $this->warehouseOf($branch);
        $khoB = $this->warehouseOf($branchB);
        $api = fn ($user) => $this->freshBrowser()->spa()->actingAs($user);

        $drug = $this->makeProduct($owner, $branch, $kg, $bao, 'TS-01', true);
        $plain = $this->makeProduct($owner, $branch, $kg, $bao, 'NPK-16');
        $kgB = $this->makeUnit($tenantB, 'Kg');
        $productB = $this->makeProduct($ownerB, $branchB, $kgB, $this->makeUnit($tenantB, 'Bao'));

        $this->postStock($owner, $kho, 'receipt', [
            ['product_id' => $drug['id'], 'unit_id' => $kg->uuid, 'quantity' => 12.5, 'unit_cost' => 5000, 'batch_no' => 'L001', 'expires_at' => '2030-01-31'],
            ['product_id' => $plain['id'], 'unit_id' => $bao->uuid, 'quantity' => 4, 'unit_cost' => 750000],
        ])->assertCreated();
        $this->postStock($owner, $kho, 'issue', [['product_id' => $plain['id'], 'unit_id' => $kg->uuid, 'quantity' => 20]], ['reason' => 'damaged'])->assertCreated();
        $this->postStock($ownerB, $khoB, 'receipt', [['product_id' => $productB['id'], 'unit_id' => $kgB->uuid, 'quantity' => 9, 'unit_cost' => 1000]])->assertCreated();

        $snapshot = fn () => $this->platform(fn () => [
            'documents' => StockDocument::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'uuid', 'code', 'type', 'reason', 'performed_by'])->toArray(),
            'movements' => StockMovement::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'document_id', 'product_id', 'batch_id', 'quantity_delta', 'balance_after', 'unit_cost', 'entered_quantity', 'entered_factor'])->toArray(),
            'balances' => StockBalance::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'warehouse_id', 'product_id', 'batch_id', 'quantity'])->toArray(),
            'costs' => ProductCost::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'product_id', 'avg_cost'])->toArray(),
            'batches' => Batch::where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'uuid', 'batch_no', 'expires_at'])->toArray(),
        ]);
        $before = $snapshot();
        $this->assertNotEmpty($before['movements']);

        $backup = $this->adminBackup($tenant);
        $this->assertSame(2, $backup->row_counts['stock_documents']);
        $this->assertSame(3, $backup->row_counts['stock_movements']);

        // Sau khi sao lưu: nhập thêm, xuất thêm, thêm một kho mới.
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $plain['id'], 'unit_id' => $kg->uuid, 'quantity' => 1000, 'unit_cost' => 1]])->assertCreated();
        $this->postStock($owner, $kho, 'issue', [['product_id' => $drug['id'], 'unit_id' => $kg->uuid, 'quantity' => 12.5, 'batch_id' => $this->platform(fn () => Batch::firstOrFail()->uuid)]], ['reason' => 'damaged'])->assertCreated();
        $this->makeWarehouse($branch);
        $this->assertNotSame($before, $snapshot());

        $this->adminRestore($backup, 'Cửa hàng Nông Phát')->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->assertSame($before, $snapshot(), 'sổ kho, số dư, giá vốn, lô, mã công khai khôi phục đúng nguyên trạng');
        $this->assertSame(1, $this->platform(fn () => Warehouse::where('tenant_id', $tenant->id)->count()), 'kho thêm sau sao lưu biến mất');

        // Phiếu mới sau khôi phục vẫn đánh số tiếp đúng và tồn vẫn đúng.
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $plain['id'], 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated()->assertJsonPath('data.code', 'PN0002');
        $this->assertSame('181.000', $api($owner)->getJson('/api/v1/stock/products?q=NPK')->json('data.0.total_qty')); // 4 Bao × 50 − 20 + 1

        // Doanh nghiệp khác không bị ảnh hưởng.
        $this->assertSame('9.000', $api($ownerB)->getJson('/api/v1/stock/products')->json('data.0.total_qty'));
        $this->assertSame(1, $this->platform(fn () => StockDocument::where('tenant_id', $tenantB->id)->count()));
    }

    public function test_the_ledger_is_still_immutable_after_a_restore(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $kho = $this->warehouseOf($branch);
        $product = $this->makeProduct($owner, $branch, $kg, $bao);
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $product['id'], 'unit_id' => $kg->uuid, 'quantity' => 5, 'unit_cost' => 1000]])->assertCreated();

        $backup = $this->adminBackup($tenant);
        $this->adminRestore($backup, 'Cửa hàng Nông Phát')->assertStatus(202)->assertJsonPath('data.status', 'completed');

        // Cờ cho phép xóa chỉ sống trong transaction khôi phục: sau đó sổ kho lại bị chặn.
        $this->assertNotSame('on', DB::selectOne("select current_setting('app.ledger_restore', true) as v")->v);
        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::table('stock_movements')->delete());
    }
}
