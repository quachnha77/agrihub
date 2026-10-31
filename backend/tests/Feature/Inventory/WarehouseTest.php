<?php

namespace Tests\Feature\Inventory;

use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\MakesStock;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Kho: nhiều kho mỗi chi nhánh, phân quyền theo chi nhánh, kho đã có phiếu thì không xóa được. */
class WarehouseTest extends TestCase
{
    use CreatesTenants, MakesCatalog, MakesStock, ReadsAuditLog, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    public function test_the_owner_adds_a_second_warehouse_to_a_branch(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();

        $response = $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $branch->uuid, 'code' => 'KHO-THUOC', 'name' => 'Kho thuốc BVTV', 'address' => 'Sau nhà'])->assertCreated();
        $response->assertJsonPath('data.code', 'KHO-THUOC')->assertJsonPath('data.name', 'Kho thuốc BVTV')->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonPath('data.status', 'active');

        $this->assertSame($tenant->id, $this->platform(fn () => Warehouse::where('code', 'KHO-THUOC')->firstOrFail()->tenant_id), 'tenant_id lấy từ context, không từ request');
        $this->assertSame(['KHO-01', 'KHO-THUOC'], array_column($this->api($owner)->getJson('/api/v1/warehouses')->json('data'), 'code'));
        $this->assertSame($branch->id, $this->lastAudit(['module' => 'warehouse', 'action' => 'created'])->branch_id ?? null);
    }

    public function test_codes_are_unique_per_branch_ignoring_case_and_can_repeat_in_another_branch(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);

        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $branchA->uuid, 'code' => 'kho-01', 'name' => 'Trùng mã'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        // Chi nhánh B có sẵn KHO-01 riêng của nó; thêm KHO-02 ở cả hai chi nhánh đều được.
        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $branchA->uuid, 'code' => 'KHO-02', 'name' => 'A2'])->assertCreated();
        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $branchB->uuid, 'code' => 'KHO-02', 'name' => 'B2'])->assertCreated();
    }

    public function test_required_fields_and_formats_are_checked_with_vietnamese_messages(): void
    {
        [, $owner, $branch] = $this->makeTenant();

        $this->api($owner)->postJson('/api/v1/warehouses', [])->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'code', 'name']);
        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $branch->uuid, 'code' => 'không hợp lệ!', 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => 'khong-phai-uuid', 'code' => 'K2', 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    }

    public function test_a_warehouse_cannot_move_to_another_branch(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $kho = $this->warehouseOf($branchA);

        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['branch_id' => $branchB->uuid])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->api($owner)->patchJson("/api/v1/warehouses/{$kho->uuid}", ['name' => 'Kho tổng', 'address' => 'Số 1'])->assertOk()->assertJsonPath('data.name', 'Kho tổng')->assertJsonPath('data.branch.id', $branchA->uuid);
    }

    public function test_listing_filters_by_branch_status_and_search(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $this->makeWarehouse($branchA, 'KHO-LANH', 'Kho lạnh');
        $this->api($owner)->patchJson('/api/v1/warehouses/'.$this->warehouseOf($branchB)->uuid, ['status' => 'inactive'])->assertOk();

        $codes = fn (string $query) => array_column($this->api($owner)->getJson("/api/v1/warehouses{$query}")->json('data'), 'code');
        $this->assertCount(3, $codes(''));
        $this->assertCount(2, $codes("?branch={$branchA->uuid}"));
        $this->assertSame(['KHO-01'], $codes('?status=inactive'));
        $this->assertSame(['KHO-LANH'], $codes('?q=lạnh'));
    }

    public function test_a_warehouse_with_documents_cannot_be_deleted_but_an_empty_one_can(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $kg = $this->makeUnit($tenant, 'Kg');
        $bao = $this->makeUnit($tenant, 'Bao');
        $kho = $this->warehouseOf($branch);
        $empty = $this->makeWarehouse($branch);
        $product = $this->makeProduct($owner, $branch, $kg, $bao);
        $this->postStock($owner, $kho, 'receipt', [['product_id' => $product['id'], 'unit_id' => $kg->uuid, 'quantity' => 1, 'unit_cost' => 1000]])->assertCreated();

        $this->api($owner)->deleteJson("/api/v1/warehouses/{$kho->uuid}")->assertStatus(409)->assertJsonPath('code', 'warehouse_in_use');
        $this->api($owner)->deleteJson("/api/v1/warehouses/{$empty->uuid}")->assertNoContent();
        $this->api($owner)->getJson("/api/v1/warehouses/{$empty->uuid}")->assertNotFound();
    }

    public function test_branch_managers_manage_only_their_branch_and_other_roles_only_view(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $khoB = $this->warehouseOf($branchB);
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branchA);
        $keeper = $this->makeUserIn($tenant, ['warehouse'], $branchA);
        $sales = $this->makeUserIn($tenant, ['sales'], $branchA);
        $delivery = $this->makeUserIn($tenant, ['delivery'], $branchA);

        // Quản lý chi nhánh: thêm/sửa kho của chi nhánh mình, không đụng chi nhánh khác, không xóa.
        $mine = $this->api($manager)->postJson('/api/v1/warehouses', ['branch_id' => $branchA->uuid, 'code' => 'KHO-M', 'name' => 'Của tôi'])->assertCreated()->json('data.id');
        $this->api($manager)->patchJson("/api/v1/warehouses/{$mine}", ['name' => 'Đổi tên'])->assertOk();
        $this->api($manager)->postJson('/api/v1/warehouses', ['branch_id' => $branchB->uuid, 'code' => 'KHO-X', 'name' => 'Chi nhánh khác'])->assertForbidden();
        $this->api($manager)->patchJson("/api/v1/warehouses/{$khoB->uuid}", ['name' => 'x'])->assertForbidden();
        $this->api($manager)->getJson("/api/v1/warehouses/{$khoB->uuid}")->assertForbidden();
        $this->api($manager)->deleteJson("/api/v1/warehouses/{$mine}")->assertForbidden();
        $this->assertSame(['KHO-01', 'KHO-M'], array_column($this->api($manager)->getJson('/api/v1/warehouses')->json('data'), 'code'));

        foreach ([$keeper, $sales] as $user) {
            $this->api($user)->getJson('/api/v1/warehouses')->assertOk();
            $this->api($user)->postJson('/api/v1/warehouses', [])->assertForbidden(); // 403 đến trước kiểm tra dữ liệu
            $this->api($user)->patchJson("/api/v1/warehouses/{$mine}", ['name' => 'x'])->assertForbidden();
        }

        $this->api($delivery)->getJson('/api/v1/warehouses')->assertForbidden();
        $this->assertNotNull($owner);
    }

    public function test_one_business_never_sees_or_touches_anothers_warehouses(): void
    {
        [, $ownerA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();
        $khoB = $this->warehouseOf($branchB);

        $this->api($ownerA)->getJson("/api/v1/warehouses/{$khoB->uuid}")->assertNotFound();
        $this->api($ownerA)->patchJson("/api/v1/warehouses/{$khoB->uuid}", ['name' => 'x'])->assertNotFound();
        $this->api($ownerA)->deleteJson("/api/v1/warehouses/{$khoB->uuid}")->assertNotFound();
        $this->api($ownerA)->postJson('/api/v1/warehouses', ['branch_id' => $branchB->uuid, 'code' => 'KHO-X', 'name' => 'Lén'])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->api($ownerA)->getJson('/api/v1/warehouses/khong-phai-uuid')->assertNotFound();
    }

    public function test_a_closed_branch_cannot_get_a_new_warehouse(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $closed = $this->makeBranch($tenant, ['status' => 'inactive']);

        $this->api($owner)->postJson('/api/v1/warehouses', ['branch_id' => $closed->uuid, 'code' => 'KHO-Z', 'name' => 'Z'])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    }
}
