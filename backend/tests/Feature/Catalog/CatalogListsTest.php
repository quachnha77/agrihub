<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Đơn vị tính, nhóm hàng, thương hiệu: danh sách dùng chung toàn doanh nghiệp. */
class CatalogListsTest extends TestCase
{
    use CreatesTenants, MakesCatalog, ReadsAuditLog, RefreshDatabase;

    /** @return array<string, array{string, string}> đường dẫn => [nhãn trong thông báo lỗi, tên mẫu] */
    public static function lists(): array
    {
        return [
            'đơn vị tính' => ['units', 'Bao'],
            'nhóm hàng' => ['categories', 'Phân bón'],
            'thương hiệu' => ['brands', 'Bayer'],
        ];
    }

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    #[DataProvider('lists')]
    public function test_the_owner_adds_renames_and_deletes_an_item(string $path, string $name): void
    {
        [, $owner] = $this->makeTenant();

        $id = $this->api($owner)->postJson("/api/v1/{$path}", ['name' => $name])->assertCreated()->assertJsonPath('data.name', $name)->assertJsonPath('data.is_active', true)->json('data.id');
        $this->api($owner)->patchJson("/api/v1/{$path}/{$id}", ['name' => $name.' mới', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.name', $name.' mới')->assertJsonPath('data.is_active', false);

        $this->assertSame([$name.' mới'], array_column($this->api($owner)->getJson("/api/v1/{$path}")->assertOk()->json('data'), 'name'));
        $this->assertCount(0, $this->api($owner)->getJson("/api/v1/{$path}?active=1")->json('data'));
        $this->assertCount(1, $this->api($owner)->getJson("/api/v1/{$path}?active=0")->json('data'));

        $this->api($owner)->deleteJson("/api/v1/{$path}/{$id}")->assertNoContent();
        $this->assertCount(0, $this->api($owner)->getJson("/api/v1/{$path}")->json('data'));
        $this->assertNotNull($this->lastAudit(['module' => 'catalog', 'action' => 'created']));
        $this->assertNotNull($this->lastAudit(['module' => 'catalog', 'action' => 'deleted']));
    }

    #[DataProvider('lists')]
    public function test_names_are_unique_per_business_ignoring_case_and_may_repeat_across_businesses(string $path, string $name): void
    {
        [$tenantA, $ownerA] = $this->makeTenant();
        [, $ownerB] = $this->makeTenant();
        $id = $this->api($ownerA)->postJson("/api/v1/{$path}", ['name' => $name])->assertCreated()->json('data.id');
        $this->api($ownerA)->postJson("/api/v1/{$path}", ['name' => 'Khác'])->assertCreated();

        $this->api($ownerA)->postJson("/api/v1/{$path}", ['name' => mb_strtoupper($name)])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api($ownerA)->postJson("/api/v1/{$path}", ['name' => "  {$name}  "])->assertStatus(422);
        $this->api($ownerA)->patchJson("/api/v1/{$path}/{$id}", ['name' => 'Khác'])->assertStatus(422)->assertJsonValidationErrors('name');
        // Giữ nguyên tên của chính nó khi sửa là hợp lệ.
        $this->api($ownerA)->patchJson("/api/v1/{$path}/{$id}", ['name' => $name, 'is_active' => true])->assertOk();

        // Doanh nghiệp khác dùng lại cùng tên được, và không thấy mục của doanh nghiệp này.
        $this->api($ownerB)->postJson("/api/v1/{$path}", ['name' => $name])->assertCreated();
        $this->assertNotContains($id, array_column($this->api($ownerB)->getJson("/api/v1/{$path}")->json('data'), 'id'));
        $this->api($ownerB)->patchJson("/api/v1/{$path}/{$id}", ['name' => 'Chiếm'])->assertNotFound();
        $this->api($ownerB)->deleteJson("/api/v1/{$path}/{$id}")->assertNotFound();
        $this->assertNotNull($tenantA);
    }

    #[DataProvider('lists')]
    public function test_name_is_required_and_limited(string $path, string $name): void
    {
        [, $owner] = $this->makeTenant();

        $this->api($owner)->postJson("/api/v1/{$path}", [])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api($owner)->postJson("/api/v1/{$path}", ['name' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api($owner)->postJson("/api/v1/{$path}", ['name' => $name, 'is_active' => 'có'])->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_categories_are_ordered_by_sort_order_then_name(): void
    {
        [, $owner] = $this->makeTenant();
        foreach ([['Hạt giống', 2], ['Phân bón', 1], ['Dụng cụ', 2]] as [$name, $order]) {
            $this->api($owner)->postJson('/api/v1/categories', ['name' => $name, 'sort_order' => $order])->assertCreated()->assertJsonPath('data.sort_order', $order);
        }

        $this->assertSame(['Phân bón', 'Dụng cụ', 'Hạt giống'], array_column($this->api($owner)->getJson('/api/v1/categories')->json('data'), 'name'));
        $this->api($owner)->postJson('/api/v1/categories', ['name' => 'X', 'sort_order' => -1])->assertStatus(422)->assertJsonValidationErrors('sort_order');
    }

    public function test_a_branch_manager_may_add_items_but_not_rename_or_delete_them(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);
        $id = $this->api($owner)->postJson('/api/v1/units', ['name' => 'Bao'])->assertCreated()->json('data.id');

        // Cần thêm đơn vị khi nhập hàng nên được thêm; đổi tên/xóa ảnh hưởng mọi chi nhánh nên chỉ cấp doanh nghiệp.
        $this->api($manager)->getJson('/api/v1/units')->assertOk()->assertJsonCount(1, 'data');
        $this->api($manager)->postJson('/api/v1/units', ['name' => 'Lốc'])->assertCreated();
        $this->api($manager)->patchJson("/api/v1/units/{$id}", ['name' => 'Bịch'])->assertForbidden();
        $this->api($manager)->deleteJson("/api/v1/units/{$id}")->assertForbidden();
    }

    public function test_roles_without_catalog_rights_cannot_even_read_the_lists(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $delivery = $this->makeUserIn($tenant, ['delivery'], $branch);
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);

        $this->api($delivery)->getJson('/api/v1/brands')->assertForbidden();
        $this->api($sales)->getJson('/api/v1/brands')->assertOk();
        $this->api($sales)->postJson('/api/v1/brands', ['name' => 'X'])->assertForbidden();
    }

    // --- đang được dùng --------------------------------------------------------------------------

    public function test_an_item_in_use_cannot_be_deleted_but_can_be_retired(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $unit = $this->makeUnit($tenant, 'Kg');
        $cat = $this->makeCategory($tenant, 'Phân');
        $brand = $this->makeBrand($tenant, 'Bayer');
        $this->api($owner)->postJson('/api/v1/products', $this->productPayload($branch, $unit, ['category_id' => $cat->uuid, 'brand_id' => $brand->uuid]))->assertCreated();

        foreach ([['units', $unit], ['categories', $cat], ['brands', $brand]] as [$path, $item]) {
            $this->api($owner)->deleteJson("/api/v1/{$path}/{$item->uuid}")->assertStatus(409)->assertJsonPath('code', 'catalog_item_in_use');
            $this->api($owner)->patchJson("/api/v1/{$path}/{$item->uuid}", ['is_active' => false])->assertOk();
        }
    }

    public function test_after_the_product_is_deleted_its_category_and_brand_can_go_but_its_unit_is_kept(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $unit = $this->makeUnit($tenant, 'Kg');
        $cat = $this->makeCategory($tenant, 'Phân');
        $brand = $this->makeBrand($tenant, 'Bayer');
        $id = $this->api($owner)->postJson('/api/v1/products', $this->productPayload($branch, $unit, ['category_id' => $cat->uuid, 'brand_id' => $brand->uuid]))->assertCreated()->json('data.id');
        $this->api($owner)->deleteJson("/api/v1/products/{$id}")->assertNoContent();

        $this->api($owner)->deleteJson("/api/v1/categories/{$cat->uuid}")->assertNoContent();
        $this->api($owner)->deleteJson("/api/v1/brands/{$brand->uuid}")->assertNoContent();
        // Đơn vị: database còn giữ tham chiếu của sản phẩm đã xóa mềm nên vẫn báo đang dùng (chỉ ngừng sử dụng được).
        $this->api($owner)->deleteJson("/api/v1/units/{$unit->uuid}")->assertStatus(409);
        $this->assertSame(1, $this->platform(fn () => Product::onlyTrashed()->count()));
    }

    // --- bộ đơn vị mặc định ---------------------------------------------------------------------------

    public function test_onboarding_gives_a_new_business_the_common_units(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->api($admin)->postJson('/api/v1/platform/tenants', [
            'tenant' => ['name' => 'Cửa hàng Mới', 'slug' => 'cua-hang-moi'],
            'owner' => ['name' => 'Chủ Mới', 'email' => 'chu@moi.vn', 'phone' => '0933333333'],
        ])->assertCreated();

        $names = $this->platform(fn () => Unit::orderBy('id')->pluck('name')->all());
        $this->assertSame(config('catalog.default_units'), $names);
        $this->assertContains('Bao', $names);
        $this->assertSame(0, $this->auditCount(['module' => 'catalog']), 'bộ mặc định không sinh audit từng dòng');
    }
}
