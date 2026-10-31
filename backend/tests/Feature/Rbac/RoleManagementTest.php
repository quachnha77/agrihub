<?php

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_listing_roles_shows_system_roles_with_permissions_and_user_counts(): void
    {
        [, $owner] = $this->makeTenant();

        $response = $this->spa()->actingAs($owner)->getJson('/api/v1/roles')->assertOk();

        $this->assertCount(7, $response->json('data'));
        $ownerRole = collect($response->json('data'))->firstWhere('key', 'owner');
        $this->assertSame(1, $ownerRole['users_count']);
        $this->assertNotEmpty($ownerRole['permissions']);
    }

    public function test_roles_of_other_tenants_are_invisible(): void
    {
        [, $ownerA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $foreign = $this->roleOf($tenantB, 'admin');

        $this->spa()->actingAs($ownerA)->getJson('/api/v1/roles/'.$foreign->uuid)->assertNotFound();
        $this->spa()->actingAs($ownerA)->putJson('/api/v1/roles/'.$foreign->uuid, ['name' => 'X'])->assertNotFound();
        $this->spa()->actingAs($ownerA)->deleteJson('/api/v1/roles/'.$foreign->uuid)->assertNotFound();
    }

    public function test_permission_catalog_is_available_to_role_viewers(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonPath('scopes', ['own', 'branch', 'tenant'])
            ->assertJsonFragment(['resource' => 'sales.order']);

        [, $delivery] = $this->makeTenant(roles: ['delivery']);
        $this->spa()->actingAs($delivery)->getJson('/api/v1/permissions')->assertForbidden();
    }

    public function test_owner_can_create_a_custom_role(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $response = $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Thủ kho mùa vụ',
            'description' => 'Tùy chỉnh',
            'permissions' => [
                ['key' => 'inventory.movement.view', 'scope' => 'branch'],
                ['key' => 'inventory.movement.create', 'scope' => 'branch'],
            ],
        ])->assertCreated();

        $this->assertFalse($response->json('data.is_system'));
        $this->assertStringStartsWith('custom-', $response->json('data.key'));
        $this->assertCount(2, $response->json('data.permissions'));
        $this->assertSame($tenant->id, $this->platform(fn () => Role::where('uuid', $response->json('data.id'))->value('tenant_id')));
    }

    public function test_role_creation_validates_permissions(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Sai',
            'permissions' => [
                ['key' => 'khong.ton.tai', 'scope' => 'tenant'],
                ['key' => 'sales.order.view', 'scope' => 'toan-the-gioi'],
            ],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['permissions.0.key', 'permissions.1.scope']]);
    }

    public function test_cannot_grant_permissions_beyond_own_privileges(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']); // Admin KHÔNG có billing.subscription.update (chỉ xem gói dịch vụ)

        $this->spa()->actingAs($admin)->postJson('/api/v1/roles', [
            'name' => 'Leo thang',
            'permissions' => [['key' => 'billing.subscription.update', 'scope' => 'tenant']],
        ])->assertForbidden()->assertJsonPath('code', 'permission_exceeds_privileges');

        // Cũng không được nâng phạm vi: Admin có crm.customer ở tenant, nhưng Branch Manager chỉ ở branch
        $manager = $this->makeUserIn($tenant, ['branch_manager']);
        $this->spa()->actingAs($manager)->postJson('/api/v1/roles', [
            'name' => 'Nâng scope',
            'permissions' => [['key' => 'crm.customer.view', 'scope' => 'tenant']],
        ])->assertForbidden();
    }

    public function test_user_without_role_create_permission_cannot_create_roles(): void
    {
        [$tenant] = $this->makeTenant();
        $manager = $this->makeUserIn($tenant, ['branch_manager']); // chỉ có tenant.role.view

        $this->spa()->actingAs($manager)->postJson('/api/v1/roles', [
            'name' => 'X', 'permissions' => [['key' => 'crm.customer.view', 'scope' => 'branch']],
        ])->assertForbidden();
    }

    public function test_owner_role_is_immutable_and_undeletable(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $ownerRole = $this->roleOf($tenant, 'owner');

        $this->spa()->actingAs($owner)->putJson('/api/v1/roles/'.$ownerRole->uuid, ['permissions' => []])->assertForbidden();
        $this->spa()->actingAs($owner)->deleteJson('/api/v1/roles/'.$ownerRole->uuid)->assertForbidden();
    }

    public function test_system_roles_cannot_be_deleted_but_custom_roles_can(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $admin = $this->roleOf($tenant, 'admin');

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/roles/'.$admin->uuid)->assertForbidden();

        $uuid = $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Tạm', 'permissions' => [['key' => 'report.report.view', 'scope' => 'own']],
        ])->json('data.id');

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/roles/'.$uuid)->assertNoContent();
    }

    public function test_cannot_delete_a_role_that_is_assigned(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $uuid = $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Đang dùng', 'permissions' => [['key' => 'report.report.view', 'scope' => 'own']],
        ])->json('data.id');
        $role = $this->platform(fn () => Role::where('uuid', $uuid)->first());
        $user = $this->makeUserIn($tenant, []);
        $this->platform(fn () => $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]));

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/roles/'.$uuid)
            ->assertStatus(409)->assertJsonPath('code', 'role_in_use');
    }

    public function test_owner_can_edit_permissions_of_a_system_role_and_effect_is_immediate(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $salesRole = $this->roleOf($tenant, 'sales');
        $salesUser = $this->makeUserIn($tenant, ['sales']);

        $this->spa()->actingAs($salesUser)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue($salesUser->hasPermission('sales.order.create'));

        $this->spa()->actingAs($owner)->putJson('/api/v1/roles/'.$salesRole->uuid, [
            'permissions' => [['key' => 'sales.order.view', 'scope' => 'branch']],
        ])->assertOk()->assertJsonCount(1, 'data.permissions');

        $this->spa()->actingAs($salesUser)->getJson('/api/v1/auth/me')->assertOk();
        $salesUser->flushPermissionCache();
        $this->assertFalse($salesUser->hasPermission('sales.order.create'));
    }

    public function test_admin_cannot_edit_a_role_more_powerful_than_themselves(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        $strong = $this->platform(function () use ($tenant) {
            $role = new Role(['name' => 'Cao cấp']);
            $role->forceFill(['tenant_id' => $tenant->id, 'key' => 'custom-cao-cap', 'is_system' => false])->save();
            app(RoleService::class)->setGrants($role, ['billing.subscription.update' => 'tenant']); // Admin không có quyền này

            return $role;
        });

        $this->spa()->actingAs($admin)->putJson('/api/v1/roles/'.$strong->uuid, ['name' => 'Đổi tên'])->assertForbidden();
        $this->spa()->actingAs($admin)->deleteJson('/api/v1/roles/'.$strong->uuid)->assertForbidden();
    }

    public function test_role_rows_carry_the_tenant_id_in_the_database(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $uuid = $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Kiểm tra', 'permissions' => [['key' => 'report.report.view', 'scope' => 'own']],
        ])->json('data.id');

        $this->platform(function () use ($uuid, $tenant) {
            $role = Role::where('uuid', $uuid)->first();
            $this->assertSame(1, DB::table('role_permissions')->where('role_id', $role->id)->where('tenant_id', $tenant->id)->count());
        });
    }
}
