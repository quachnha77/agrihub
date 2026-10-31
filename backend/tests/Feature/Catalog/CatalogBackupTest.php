<?php

namespace Tests\Feature\Catalog;

use App\Models\Backup;
use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\TestCase;

/**
 * Danh mục sản phẩm nằm trong sao lưu / khôi phục theo doanh nghiệp (dùng danh sách bảng THẬT của config/backup.php, không phải bảng giả):
 * khôi phục trả lại đúng sản phẩm, đơn vị, giá, mã vạch và thông tin đặc thù (JSONB), và không đụng tới dữ liệu doanh nghiệp khác.
 */
class CatalogBackupTest extends TestCase
{
    use AdministersBackups, CreatesTenants, MakesCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    public function test_the_catalog_tables_are_registered_for_backup_in_parent_to_child_order(): void
    {
        $tables = config('backup.tenant_tables');

        $this->assertSame(['units', 'categories', 'brands', 'products', 'product_units'], array_values(array_intersect($tables, ['units', 'categories', 'brands', 'products', 'product_units'])));
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['id', 'tenant_id']), "{$table} phải có id và tenant_id");
        }
    }

    public function test_restoring_brings_back_deleted_and_changed_products_exactly(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        [$otherTenant, $otherOwner, $otherBranch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $cat = $this->makeCategory($tenant, 'Thuốc BVTV');
        $other = $this->makeUnit($otherTenant, 'Kg');
        // Hai chủ doanh nghiệp khác mật khẩu: mỗi lần gọi bắt đầu như một trình duyệt mới (xem CreatesTenants::freshBrowser).
        $api = fn ($user) => $this->freshBrowser()->spa()->actingAs($user);

        $keep = $api($owner)->postJson('/api/v1/products', $this->pesticidePayload($branch, $kg, ['category_id' => $cat->uuid, 'units' => [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1000.5, 'price_credit' => 1100, 'barcode' => '8930001'],
            ['unit_id' => $bao->uuid, 'factor' => 25, 'price_cash' => 24000, 'price_credit' => 26000, 'barcode' => '8930002'],
        ]]))->assertCreated()->json('data');
        $gone = $api($owner)->postJson('/api/v1/products', $this->productPayload($branch, $kg, ['sku' => 'GONE', 'name' => 'Sẽ bị xóa']))->assertCreated()->json('data.id');
        $theirs = $api($otherOwner)->postJson('/api/v1/products', $this->productPayload($otherBranch, $other, ['sku' => 'THEIRS']))->assertCreated()->json('data.id');

        $backup = $this->asBackupAdmin()->postJson($this->backupPath($tenant))->assertStatus(202)->assertJsonPath('data.status', 'completed')->json('data');
        $this->assertEqualsCanonicalizing(['units', 'categories', 'products', 'product_units'], array_keys(array_filter($backup['row_counts'], fn ($n) => $n > 0)));

        // Sau khi sao lưu: sửa giá, xóa một sản phẩm, thêm một sản phẩm mới.
        $api($owner)->patchJson("/api/v1/products/{$keep['id']}", ['name' => 'Tên đã đổi', 'units' => [['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 1, 'price_credit' => 1]]])->assertOk();
        $api($owner)->deleteJson("/api/v1/products/{$gone}")->assertNoContent();
        $api($owner)->postJson('/api/v1/products', $this->productPayload($branch, $kg, ['sku' => 'NEW', 'name' => 'Mới thêm sau sao lưu']))->assertCreated();

        $this->asBackupAdmin()->postJson($this->backupPath($tenant, "/{$backup['id']}/restore"), ['password' => self::ADMIN_PASSWORD, 'confirm_name' => 'Cửa hàng Nông Phát'])
            ->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $restored = $api($owner)->getJson('/api/v1/products?per_page=50')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['T-001', 'GONE'], array_column($restored, 'sku'), 'sản phẩm bị xóa trở lại, sản phẩm thêm sau sao lưu biến mất');

        $back = collect($restored)->firstWhere('sku', 'T-001');
        $this->assertSame($keep['name'], $back['name']);
        $this->assertSame($keep['id'], $back['id'], 'mã công khai (uuid) được giữ nguyên');
        $this->assertSame('1234/CP-BVTV', $back['specs']['registration_no']);
        $this->assertSame(7, $back['specs']['phi_days']);
        $this->assertSame('III', $back['specs']['toxicity_group']);
        $this->assertTrue($back['track_batch']);
        $this->assertSame('Thuốc BVTV', $back['category']['name']);
        $this->assertSame(['1000.50', '24000.00'], array_column($back['units'], 'price_cash'));
        $this->assertSame(['1100.00', '26000.00'], array_column($back['units'], 'price_credit'));
        $this->assertSame(['8930001', '8930002'], array_column($back['units'], 'barcode'));

        // Doanh nghiệp khác không bị ảnh hưởng.
        $this->assertSame([$theirs], array_column($api($otherOwner)->getJson('/api/v1/products')->json('data'), 'id'));
        // Dữ liệu sau khôi phục vẫn nhất quán: đơn vị cơ sở đúng một dòng hệ số 1 cho mỗi sản phẩm.
        $this->assertSame(0, $this->platform(fn () => DB::table('products')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('product_units')
            ->whereColumn('product_units.product_id', 'products.id')->whereColumn('product_units.unit_id', 'products.base_unit_id')->where('factor', 1))->count()));
        $this->assertSame(1, $this->platform(fn () => Backup::where('kind', Backup::KIND_PRE_RESTORE)->count()), 'điểm quay lại được tạo trước khi ghi đè');
    }

    public function test_the_pre_restore_point_undoes_a_restore(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        $kg = $this->makeUnit($tenant, 'Kg');
        $api = fn () => $this->freshBrowser()->spa()->actingAs($owner); // cùng một test dùng cả chủ doanh nghiệp và Super Admin: mỗi lần là một trình duyệt mới

        $api()->postJson('/api/v1/products', $this->productPayload($branch, $kg, ['sku' => 'OLD']))->assertCreated();
        $backup = $this->asBackupAdmin()->postJson($this->backupPath($tenant))->assertStatus(202)->json('data.id');
        $api()->postJson('/api/v1/products', $this->productPayload($branch, $kg, ['sku' => 'LATER']))->assertCreated();

        $run = $this->asBackupAdmin()->postJson($this->backupPath($tenant, "/{$backup}/restore"), ['password' => self::ADMIN_PASSWORD, 'confirm_name' => 'Cửa hàng Nông Phát'])->assertStatus(202)->json('data');
        $this->assertSame(['OLD'], array_column($api()->getJson('/api/v1/products')->json('data'), 'sku'));

        // Khôi phục lại điểm quay lại ⇒ sản phẩm thêm sau sao lưu trở lại.
        $pre = $this->asBackupAdmin()->getJson($this->backupPath($tenant, '/restores'))->json('data.0.pre_restore_backup_id');
        $this->assertNotNull($pre);
        $this->asBackupAdmin()->postJson($this->backupPath($tenant, "/{$pre}/restore"), ['password' => self::ADMIN_PASSWORD, 'confirm_name' => 'Cửa hàng Nông Phát'])->assertStatus(202)->assertJsonPath('data.status', 'completed');
        $this->assertEqualsCanonicalizing(['OLD', 'LATER'], array_column($api()->getJson('/api/v1/products')->json('data'), 'sku'));
        $this->assertNotNull($run);
        $this->assertSame(2, $this->platform(fn () => Product::count()));
        $this->assertSame(2, $this->platform(fn () => ProductUnit::count()));
    }
}
