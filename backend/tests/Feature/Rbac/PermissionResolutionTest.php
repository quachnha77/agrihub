<?php

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\Tenant;
use App\Services\Rbac\RoleProvisioner;
use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PermissionResolutionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_new_tenant_gets_all_system_roles_with_default_grants(): void
    {
        [$tenant] = $this->makeTenant();

        $keys = $this->platform(fn () => Role::where('tenant_id', $tenant->id)->pluck('key')->all());
        $this->assertEqualsCanonicalizing(Catalog::roleKeys(), $keys);

        $this->assertSame(
            Catalog::roleGrants('accountant'),
            $this->platform(fn () => app(RoleService::class)->grants(Role::where('tenant_id', $tenant->id)->where('key', 'accountant')->first())),
        );
    }

    public function test_effective_permissions_take_the_widest_scope_across_roles(): void
    {
        [$tenant, $user] = $this->makeTenant(roles: ['sales', 'accountant']);

        $map = $user->permissionMap();

        // sales: sales.order.view = branch ; accountant: = tenant => tenant
        $this->assertSame('tenant', $map['sales.order.view']);
        // chỉ sales mới có create (branch)
        $this->assertSame('branch', $map['sales.order.create']);
    }

    public function test_user_without_roles_has_no_permissions(): void
    {
        [, $user] = $this->makeTenant(roles: []);

        $this->assertSame([], $user->permissionMap());
        $this->assertFalse(Gate::forUser($user)->allows('sales.order.view'));
    }

    public function test_gate_abilities_follow_permissions_and_minimum_scope(): void
    {
        [, $user] = $this->makeTenant(roles: ['warehouse']);

        $gate = Gate::forUser($user);
        $this->assertTrue($gate->allows('inventory.movement.create'));
        $this->assertFalse($gate->allows('inventory.movement.approve'));
        $this->assertFalse($gate->allows('finance.cashbook.view'));
        $this->assertTrue($user->hasPermission('inventory.movement.create', 'branch'));
        $this->assertFalse($user->hasPermission('inventory.movement.create', 'tenant'));
    }

    public function test_platform_admin_has_no_tenant_permissions(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->assertSame([], $admin->permissionMap());
    }

    public function test_roles_are_isolated_between_tenants(): void
    {
        [$a, $userA] = $this->makeTenant();
        [$b] = $this->makeTenant();

        $ownerB = $this->roleOf($b, 'owner');
        // User A không thấy vai trò của Tenant B kể cả khi truy vấn thô (RLS)
        app(TenantContext::class)->runForTenant($a->id, function () use ($ownerB) {
            $this->assertSame(0, DB::table('roles')->where('id', $ownerB->id)->count());
            $this->assertSame(0, DB::table('role_permissions')->where('role_id', $ownerB->id)->count());
        });
    }

    public function test_provisioning_is_idempotent_and_keeps_tenant_customisations(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->roleOf($tenant, 'admin');

        // Tenant tùy chỉnh vai trò Admin: chỉ còn xem sản phẩm
        $this->platform(fn () => app(RoleService::class)->setGrants($admin, ['catalog.product.view' => 'tenant']));

        $this->platform(fn () => app(RoleProvisioner::class)->provision(Tenant::find($tenant->id)));

        $after = $this->platform(fn () => app(RoleService::class)->grants($admin));
        $this->assertSame(['catalog.product.view' => 'tenant'], $after);
        $this->assertSame(7, $this->platform(fn () => Role::where('tenant_id', $tenant->id)->count()));
    }

    public function test_owner_is_always_restored_to_full_permissions(): void
    {
        [$tenant] = $this->makeTenant();
        $owner = $this->roleOf($tenant, 'owner');
        $this->platform(fn () => app(RoleService::class)->setGrants($owner, []));

        $this->platform(fn () => app(RoleProvisioner::class)->provision(Tenant::find($tenant->id)));

        $this->assertCount(
            count(Catalog::permissions()),
            $this->platform(fn () => app(RoleService::class)->grants($owner)),
        );
    }

    public function test_sync_command_runs(): void
    {
        $this->makeTenant();

        $this->artisan('agrihub:sync-permissions')->assertSuccessful();
    }

    public function test_me_returns_roles_and_permission_map(): void
    {
        [, $user] = $this->makeTenant(roles: ['warehouse']);

        $response = $this->spa()->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0.key', 'warehouse');

        $this->assertSame('branch', $response->json('data.permissions')['inventory.movement.create']);
    }
}
