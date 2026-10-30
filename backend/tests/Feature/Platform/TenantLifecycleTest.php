<?php

namespace Tests\Feature\Platform;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class TenantLifecycleTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1', 'user_agent' => 'PHPUnit', 'payload' => 'x', 'last_activity' => time()]);
    }

    // --- Đọc ------------------------------------------------------------------------------

    public function test_listing_shows_counts_owners_and_supports_search_filter_and_sort(): void
    {
        $admin = $this->makePlatformAdmin();
        [$a] = $this->makeTenant(['name' => 'Nông Phát', 'slug' => 'nong-phat', 'tax_code' => '0100000001'], ['email' => 'owner.a@example.com']);
        [$b] = $this->makeTenant(['name' => 'Vật tư Xanh', 'slug' => 'vat-tu-xanh'], ['email' => 'owner.b@example.com']);
        $this->platform(fn () => $b->forceFill(['status' => 'suspended'])->save());
        $this->makeUserIn($a, ['sales']);

        $get = fn (string $qs = '') => $this->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants?'.$qs)->assertOk();

        $all = $get()->json('data');
        $this->assertCount(2, $all);
        $rowA = collect($all)->firstWhere('slug', 'nong-phat');
        $this->assertSame(2, $rowA['users_count']);
        $this->assertSame(1, $rowA['branches_count']);
        $this->assertSame(['owner.a@example.com'], collect($rowA['owners'])->pluck('email')->all());

        $this->assertSame(['nong-phat'], collect($get('q=nong')->json('data'))->pluck('slug')->all());
        $this->assertSame(['nong-phat'], collect($get('q=0100000001')->json('data'))->pluck('slug')->all());
        $this->assertSame(['vat-tu-xanh'], collect($get('status=suspended')->json('data'))->pluck('slug')->all());
        $this->assertSame(['nong-phat', 'vat-tu-xanh'], collect($get('sort=name')->json('data'))->pluck('slug')->all());
        $this->assertSame(['vat-tu-xanh', 'nong-phat'], collect($get('sort=-name')->json('data'))->pluck('slug')->all());
        $this->assertCount(1, $get('per_page=1')->json('data'));
        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants?status=khong-hop-le')->assertStatus(422);
    }

    public function test_show_returns_branches_and_owners(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner, $branch] = $this->makeTenant();

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants/'.$tenant->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->uuid)
            ->assertJsonPath('data.owners.0.id', $owner->uuid)
            ->assertJsonPath('data.branches.0.id', $branch->uuid);

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_tenant_users_cannot_use_platform_tenant_endpoints(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $base = '/api/v1/platform/tenants';

        $this->spa()->actingAs($owner)->getJson($base)->assertForbidden();
        $this->spa()->actingAs($owner)->getJson("$base/{$tenant->uuid}")->assertForbidden();
        $this->spa()->actingAs($owner)->patchJson("$base/{$tenant->uuid}", ['name' => 'Chiếm quyền'])->assertForbidden();
        $this->spa()->actingAs($owner)->postJson("$base/{$tenant->uuid}/suspend", ['reason' => 'tự khóa mình'])->assertForbidden();
        $this->spa()->actingAs($owner)->postJson("$base/{$tenant->uuid}/activate")->assertForbidden();
    }

    // --- Sửa ------------------------------------------------------------------------------

    public function test_update_changes_profile_fields_and_is_audited(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant(['name' => 'Tên cũ']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/tenants/'.$tenant->uuid, [
            'name' => 'Tên mới', 'phone' => '0911222333', 'address' => 'Địa chỉ mới', 'tax_code' => '0312345678-001',
        ])->assertOk()->assertJsonPath('data.name', 'Tên mới')->assertJsonPath('data.tax_code', '0312345678-001');

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'updated', 'tenant_id' => $tenant->id]);
        $this->assertSame('Tên cũ', $log->before['name']);
        $this->assertSame('Tên mới', $log->after['name']);
        $this->assertSame($admin->uuid, $log->actor_uuid);
    }

    public function test_slug_and_status_cannot_be_changed_through_update(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant(['slug' => 'giu-nguyen']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/tenants/'.$tenant->uuid, ['slug' => 'doi-slug'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['slug']]);
        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/tenants/'.$tenant->uuid, ['status' => 'suspended'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);

        $fresh = $this->platform(fn () => Tenant::find($tenant->id));
        $this->assertSame('giu-nguyen', $fresh->slug);
        $this->assertSame('active', $fresh->status);
    }

    // --- Khóa / mở khóa ------------------------------------------------------------------

    public function test_suspending_requires_a_reason(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/suspend', [])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/suspend', ['reason' => 'ngắn'])
            ->assertStatus(422);

        $this->assertSame('active', $this->platform(fn () => Tenant::find($tenant->id)->status));
    }

    public function test_suspending_locks_the_tenant_signs_everyone_out_and_is_audited(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        [, $otherOwner] = $this->makeTenant();
        $this->insertSession('owner-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $owner->id);
        $this->insertSession('staff-session-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $staff->id);
        $this->insertSession('other-tenant-session-ccccccccccccccccccccccc', $otherOwner->id);
        $this->insertSession('admin-session-dddddddddddddddddddddddddddddddd', $admin->id);

        $this->spa()->actingAs($admin)
            ->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/suspend', ['reason' => 'Chưa thanh toán gói dịch vụ quá hạn'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Chưa thanh toán gói dịch vụ quá hạn');

        $remaining = DB::table('sessions')->pluck('id')->all();
        $this->assertEqualsCanonicalizing(['other-tenant-session-ccccccccccccccccccccccc', 'admin-session-dddddddddddddddddddddddddddddddd'], $remaining);

        $this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');

        $fresh = $this->platform(fn () => Tenant::find($tenant->id));
        $this->assertNotNull($fresh->suspended_at);

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'suspended']);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame('Chưa thanh toán gói dịch vụ quá hạn', $log->reason);
        $this->assertSame(['status' => 'active'], $log->before);
        $this->assertSame(['status' => 'suspended'], $log->after);
        $this->assertSame(2, $log->metadata['sessions_revoked']);
        $this->assertSame(0, $this->auditCount(['module' => 'tenant', 'action' => 'updated', 'tenant_id' => $tenant->id]), 'Không ghi trùng sự kiện updated');
    }

    public function test_other_tenants_are_unaffected_by_a_suspension(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        [, $otherOwner] = $this->makeTenant();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/suspend', ['reason' => 'Vi phạm điều khoản sử dụng'])->assertOk();

        $this->spa()->actingAs($otherOwner)->getJson('/api/v1/branches')->assertOk();
    }

    public function test_suspending_twice_conflicts_and_activating_restores_access(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner] = $this->makeTenant();
        $url = '/api/v1/platform/tenants/'.$tenant->uuid;

        $this->spa()->actingAs($admin)->postJson("$url/activate")->assertStatus(409)->assertJsonPath('code', 'tenant_not_suspended');
        $this->spa()->actingAs($admin)->postJson("$url/suspend", ['reason' => 'Tạm khóa để kiểm tra'])->assertOk();
        $this->spa()->actingAs($admin)->postJson("$url/suspend", ['reason' => 'Tạm khóa lần nữa'])->assertStatus(409)->assertJsonPath('code', 'tenant_already_suspended');

        $this->spa()->actingAs($admin)->postJson("$url/activate")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.suspension_reason', null)->assertJsonPath('data.suspended_at', null);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'activated']);
        $this->assertSame('Tạm khóa để kiểm tra', $log->metadata['previous_suspension_reason']);
        $this->assertSame($admin->uuid, $log->actor_uuid);
    }

    public function test_tenant_data_is_kept_while_suspended(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, , $branch] = $this->makeTenant();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/suspend', ['reason' => 'Khóa tạm thời để rà soát'])->assertOk();

        $this->assertNotNull($this->platform(fn () => Branch::find($branch->id)));
        $this->assertSame(1, $this->platform(fn () => User::where('tenant_id', $tenant->id)->count()));
    }
}
