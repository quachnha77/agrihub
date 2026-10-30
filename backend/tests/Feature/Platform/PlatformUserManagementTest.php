<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class PlatformUserManagementTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_listing_and_filters(): void
    {
        $admin = $this->makePlatformAdmin(['name' => 'Quản trị nền tảng', 'email' => 'root@agrihub.test']);
        [$tenantA, $ownerA] = $this->makeTenant(['name' => 'A'], ['name' => 'Nguyễn Văn Một', 'email' => 'mot@a.test']);
        [, $ownerB] = $this->makeTenant(['name' => 'B'], ['name' => 'Trần Thị Hai', 'email' => 'hai@b.test', 'status' => User::STATUS_DISABLED]);

        $get = fn (string $qs) => collect($this->spa()->actingAs($admin)->getJson('/api/v1/platform/users?per_page=100&'.$qs)->assertOk()->json('data'));

        $this->assertCount(3, $get(''));
        $this->assertSame([$ownerA->uuid], $get('q=nguyễn')->pluck('id')->all());
        $this->assertSame([$ownerB->uuid], $get('q=hai@b')->pluck('id')->all());
        $this->assertSame([$ownerB->uuid], $get('status=disabled')->pluck('id')->all());
        $this->assertSame([$ownerA->uuid], $get('tenant='.$tenantA->uuid)->pluck('id')->all());
        $this->assertSame([$admin->uuid], $get('tenant=none')->pluck('id')->all());
        $this->assertSame('A', $get('tenant='.$tenantA->uuid)->first()['tenant']['name']);
        $this->assertSame(['owner'], collect($get('tenant='.$tenantA->uuid)->first()['roles'])->pluck('key')->all());
    }

    public function test_tenant_users_cannot_use_platform_user_endpoints(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);

        $this->spa()->actingAs($owner)->getJson('/api/v1/platform/users')->assertForbidden();
        $this->spa()->actingAs($owner)->patchJson('/api/v1/platform/users/'.$staff->uuid, ['status' => 'disabled'])->assertForbidden();
        $this->spa()->actingAs($owner)->postJson('/api/v1/platform/users/'.$staff->uuid.'/reset-password')->assertForbidden();
    }

    public function test_disabling_a_user_signs_them_out_blocks_login_and_is_audited(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        DB::table('sessions')->insert(['id' => 'staff-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $staff->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$staff->uuid, ['status' => 'disabled', 'reason' => 'Nhân viên đã nghỉ việc'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $log = $this->lastAudit(['module' => 'user', 'action' => 'status_changed']);
        $this->assertSame(['status' => 'active'], $log->before);
        $this->assertSame(['status' => 'disabled'], $log->after);
        $this->assertSame('Nhân viên đã nghỉ việc', $log->reason);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame($tenant->id, $log->tenant_id);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$staff->uuid, ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertOk();
    }

    public function test_status_change_is_idempotent_and_validated(): void
    {
        $admin = $this->makePlatformAdmin();
        [, $owner] = $this->makeTenant();
        $before = $this->auditCount(['action' => 'status_changed']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$owner->uuid, ['status' => 'active'])->assertOk();
        $this->assertSame($before, $this->auditCount(['action' => 'status_changed']), 'Không đổi gì thì không ghi log');

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$owner->uuid, ['status' => 'xoa'])->assertStatus(422);
        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$owner->uuid, [])->assertStatus(422);
    }

    public function test_a_platform_admin_cannot_disable_themselves(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$admin->uuid, ['status' => 'disabled'])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
        $this->assertSame('active', $this->platform(fn () => User::find($admin->id)->status));
    }

    public function test_a_platform_admin_can_disable_another_platform_admin(): void
    {
        $admin = $this->makePlatformAdmin();
        $other = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/'.$other->uuid, ['status' => 'disabled'])->assertOk();
    }

    public function test_reset_password_issues_a_one_time_temporary_password(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner] = $this->makeTenant();
        $this->platform(fn () => $owner->forceFill(['failed_login_attempts' => 3, 'locked_until' => now()->addHour()])->save());
        DB::table('sessions')->insert(['id' => 'owner-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time()]);

        $response = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/users/'.$owner->uuid.'/reset-password')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $temporary = $response->json('temporary_password');
        $this->assertGreaterThanOrEqual(12, strlen($temporary));

        $fresh = $this->platform(fn () => User::find($owner->id));
        $this->assertTrue(Hash::check($temporary, $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until, 'Reset cũng mở khóa đăng nhập sai');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());

        $log = $this->lastAudit(['module' => 'user', 'action' => 'password_reset']);
        $this->assertSame('platform', $log->metadata['by']);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertStringNotContainsString($temporary, json_encode($this->auditRows()->all()));
        $this->assertSame(0, $this->auditCount(['module' => 'user', 'action' => 'updated', 'subject_id' => $owner->uuid]), 'Không ghi trùng sự kiện updated');

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => $temporary])
            ->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    public function test_reset_password_is_refused_for_platform_admins_and_self(): void
    {
        $admin = $this->makePlatformAdmin();
        $other = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/users/'.$other->uuid.'/reset-password')
            ->assertStatus(422)->assertJsonPath('code', 'not_allowed_for_platform_admin');
        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/users/'.$admin->uuid.'/reset-password')->assertStatus(422);
    }

    public function test_unknown_user_returns_404(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->patchJson('/api/v1/platform/users/00000000-0000-4000-8000-000000000000', ['status' => 'disabled'])->assertNotFound();
        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/users/00000000-0000-4000-8000-000000000000/reset-password')->assertNotFound();
    }
}
