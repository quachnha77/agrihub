<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Kiểm thử chống truy cập chéo Tenant ở 3 lớp: API/route-binding, Eloquent scope, PostgreSQL RLS.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // --- API -------------------------------------------------------------------------------

    public function test_user_only_lists_branches_of_own_tenant(): void
    {
        [, $userA, $branchA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();

        $response = $this->spa()->actingAs($userA)->getJson('/api/v1/branches')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($branchA->uuid));
        $this->assertFalse($ids->contains($branchB->uuid));
    }

    public function test_user_cannot_read_branch_of_another_tenant(): void
    {
        [, $userA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();

        $this->spa()->actingAs($userA)->getJson('/api/v1/branches/'.$branchB->uuid)
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_user_can_read_branch_of_own_tenant(): void
    {
        [, $userA, $branchA] = $this->makeTenant();

        $this->spa()->actingAs($userA)->getJson('/api/v1/branches/'.$branchA->uuid)
            ->assertOk()->assertJsonPath('data.id', $branchA->uuid);
    }

    public function test_tenant_id_sent_by_client_is_ignored(): void
    {
        [, $userA, $branchA] = $this->makeTenant();
        [$tenantB, , $branchB] = $this->makeTenant();

        $response = $this->spa()->actingAs($userA)
            ->getJson('/api/v1/branches?tenant_id='.$tenantB->id.'&tenant='.$tenantB->uuid)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$branchA->uuid], $ids->all());
        $this->assertFalse($ids->contains($branchB->uuid));

        $this->spa()->actingAs($userA)->withHeaders(['X-Tenant-Id' => (string) $tenantB->id])
            ->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_platform_admin_cannot_use_tenant_endpoints(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->makeTenant();

        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')
            ->assertForbidden()->assertJsonPath('code', 'tenant_context_required');
    }

    public function test_session_of_suspended_tenant_is_rejected(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->platform(fn () => $tenant->forceFill(['status' => 'suspended'])->save());

        $this->spa()->actingAs($user)->getJson('/api/v1/branches')
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
    }

    public function test_session_of_disabled_user_is_rejected(): void
    {
        [, $user] = $this->makeTenant();
        $this->platform(fn () => $user->forceFill(['status' => User::STATUS_DISABLED])->save());

        $this->spa()->actingAs($this->platform(fn () => $user->fresh()))->getJson('/api/v1/branches')
            ->assertForbidden()->assertJsonPath('code', 'account_disabled');
    }

    public function test_search_filter_stays_inside_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenantA->id, 'code' => 'HN-01', 'name' => 'Hà Nội']));
        [$tenantB] = $this->makeTenant();
        $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'HN-02', 'name' => 'Hà Nội B']));

        $codes = collect($this->spa()->actingAs($userA)->getJson('/api/v1/branches?q=hà nội')->assertOk()->json('data'))->pluck('code');

        $this->assertSame(['HN-01'], $codes->all());
    }

    // --- Eloquent scope --------------------------------------------------------------------

    public function test_model_queries_are_scoped_and_fail_closed(): void
    {
        [$tenantA, , $branchA] = $this->makeTenant();
        [$tenantB, , $branchB] = $this->makeTenant();
        $ctx = app(TenantContext::class);

        $this->assertSame(0, Branch::count(), 'Không có context => không thấy gì');

        $ctx->runForTenant($tenantA->id, function () use ($branchA, $branchB) {
            $this->assertSame([$branchA->id], Branch::pluck('id')->all());
            $this->assertNull(Branch::find($branchB->id));
        });

        $ctx->runAsPlatform(fn () => $this->assertGreaterThanOrEqual(2, Branch::count()));
    }

    public function test_creating_a_record_always_uses_the_current_tenant(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $branch = app(TenantContext::class)->runForTenant($tenantA->id, function () use ($tenantB) {
            $b = new Branch(['code' => 'X1', 'name' => 'X']);
            $b->tenant_id = $tenantB->id; // cố tình gán Tenant khác
            $b->save();

            return $b;
        });

        $this->assertSame($tenantA->id, $branch->tenant_id);
    }

    public function test_mass_assignment_cannot_set_tenant_id(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $branch = app(TenantContext::class)->runForTenant($tenantA->id, fn () => Branch::create([
            'code' => 'MA-1', 'name' => 'Mass', 'tenant_id' => $tenantB->id,
        ]));

        $this->assertSame($tenantA->id, $branch->tenant_id);
    }

    public function test_creating_without_context_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Branch::create(['code' => 'Z', 'name' => 'Không context']);
    }

    // --- PostgreSQL Row-Level Security (bỏ qua Eloquent) -----------------------------------

    public function test_application_database_role_cannot_bypass_rls(): void
    {
        // Superuser / BYPASSRLS bỏ qua RLS kể cả khi FORCE: ứng dụng không bao giờ được kết nối bằng role như vậy.
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertFalse($role->rolsuper, 'Role DB của ứng dụng đang là superuser.');
        $this->assertFalse($role->rolbypassrls, 'Role DB của ứng dụng đang có BYPASSRLS.');
    }

    public function test_rls_hides_other_tenants_rows_from_raw_queries(): void
    {
        [$tenantA, , $branchA] = $this->makeTenant();
        [$tenantB, , $branchB] = $this->makeTenant();
        $ctx = app(TenantContext::class);

        $this->assertSame(0, DB::table('branches')->count(), 'Không có context => RLS trả 0 dòng');
        $this->assertSame(0, DB::table('users')->count());

        $ctx->runForTenant($tenantA->id, function () use ($tenantA, $branchA, $branchB) {
            $this->assertSame([$branchA->id], DB::table('branches')->pluck('id')->all());
            $this->assertSame(1, DB::table('tenants')->count());
            $this->assertSame($tenantA->id, DB::table('tenants')->value('id'));
            $this->assertSame(0, DB::table('branches')->where('id', $branchB->id)->count());
            $this->assertTrue(DB::table('users')->pluck('tenant_id')->every(fn ($id) => $id === $tenantA->id));
        });

        $ctx->runAsPlatform(fn () => $this->assertGreaterThanOrEqual(2, DB::table('tenants')->count()));
    }

    public function test_rls_blocks_inserting_rows_for_another_tenant(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->expectException(QueryException::class);

        app(TenantContext::class)->runForTenant($tenantA->id, fn () => DB::table('branches')->insert([
            'tenant_id' => $tenantB->id, 'code' => 'HACK', 'name' => 'Chèn chéo Tenant',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_rls_blocks_updating_and_deleting_other_tenants_rows(): void
    {
        [$tenantA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();

        app(TenantContext::class)->runForTenant($tenantA->id, function () use ($branchB) {
            $this->assertSame(0, DB::table('branches')->where('id', $branchB->id)->update(['name' => 'Bị sửa']));
            $this->assertSame(0, DB::table('branches')->where('id', $branchB->id)->delete());
        });

        $this->assertNotSame('Bị sửa', $this->platform(fn () => Branch::find($branchB->id)->name));
    }

    public function test_database_rejects_user_that_belongs_to_no_tenant_unless_platform_admin(): void
    {
        $this->expectException(QueryException::class);

        $this->platform(fn () => User::factory()->create(['tenant_id' => null]));
    }

    public function test_branch_codes_are_unique_per_tenant_not_globally(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $ctx = app(TenantContext::class);

        $ctx->runForTenant($tenantA->id, fn () => Branch::create(['code' => 'CHUNG', 'name' => 'A']));
        $ctx->runForTenant($tenantB->id, fn () => Branch::create(['code' => 'CHUNG', 'name' => 'B']));

        $this->assertSame(2, $this->platform(fn () => Branch::where('code', 'CHUNG')->count()));

        $this->expectException(QueryException::class);
        $ctx->runForTenant($tenantA->id, fn () => Branch::create(['code' => 'CHUNG', 'name' => 'A trùng']));
    }

    public function test_tenant_factory_creates_independent_tenants(): void
    {
        [$a] = $this->makeTenant();
        [$b] = $this->makeTenant();

        $this->assertNotSame($a->id, $b->id);
        $this->assertInstanceOf(Tenant::class, $a);
    }
}
