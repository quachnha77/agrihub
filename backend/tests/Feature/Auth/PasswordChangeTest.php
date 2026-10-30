<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const NEW_PASSWORD = 'Mat-khau-moi-2026';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function change($user, array $body)
    {
        return $this->spa()->actingAs($user)->postJson('/api/v1/auth/password', $body);
    }

    private function valid(array $override = []): array
    {
        return $override + ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }

    public function test_changing_the_password_works_and_the_new_one_is_used(): void
    {
        [, $user] = $this->makeTenant();

        $this->change($user, $this->valid())->assertOk()->assertJsonPath('data.must_change_password', false);

        $fresh = $this->platform(fn () => User::find($user->id));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertNotNull($fresh->password_changed_at);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_other_devices_are_signed_out_and_the_event_is_audited_without_secrets(): void
    {
        [, $user] = $this->makeTenant();
        [, $stranger] = $this->makeTenant();
        DB::table('sessions')->insert([
            ['id' => 'device-a-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'device-b-session-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'stranger-session-ccccccccccccccccccccccccccccccccc', 'user_id' => $stranger->id, 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->change($user, $this->valid())->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'device-%')->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $stranger->id)->count(), 'Người khác không bị ảnh hưởng');

        $log = $this->lastAudit(['module' => 'auth', 'action' => 'password_changed']);
        $this->assertSame($user->uuid, $log->actor_uuid);
        $this->assertSame(2, $log->metadata['other_sessions_revoked']);
        $this->assertFalse($log->metadata['forced_change']);
        $this->assertSame(0, $this->auditCount(['module' => 'user', 'action' => 'updated', 'subject_id' => $user->uuid]), 'Không ghi trùng sự kiện updated');
        $dump = json_encode($this->auditRows()->all());
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $dump);
    }

    public function test_the_current_password_must_be_correct_and_failures_are_audited(): void
    {
        [, $user] = $this->makeTenant();

        $this->change($user, $this->valid(['current_password' => 'sai-mat-khau']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);

        $this->assertTrue(Hash::check('password', $this->platform(fn () => User::find($user->id))->password));
        $log = $this->lastAudit(['action' => 'password_change_failed']);
        $this->assertSame('failure', $log->result);
        $this->assertSame('invalid_current_password', $log->reason);
        $this->assertSame($user->uuid, $log->actor_uuid);
    }

    public function test_new_password_validation(): void
    {
        [, $user] = $this->makeTenant();

        $this->change($user, $this->valid(['password' => 'ngan1', 'password_confirmation' => 'ngan1']))->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
        $this->change($user, $this->valid(['password_confirmation' => 'khac-han-mat-khau']))->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
        $this->change($user, $this->valid(['password' => 'password', 'password_confirmation' => 'password']))->assertStatus(422);
        $this->change($user, $this->valid(['password' => 'Password1234', 'password_confirmation' => 'Password1234']))->assertStatus(422); // quá phổ biến
        $this->change($user, ['current_password' => 'password'])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

        $this->assertTrue(Hash::check('password', $this->platform(fn () => User::find($user->id))->password));
    }

    public function test_the_password_cannot_contain_the_login_name(): void
    {
        [, $user] = $this->makeTenant(user: ['email' => 'nguyenvana@example.com']);

        $this->change($user, $this->valid(['password' => 'NguyenVanA-2026', 'password_confirmation' => 'NguyenVanA-2026']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        [, $user] = $this->makeTenant();
        $this->platform(fn () => $user->forceFill(['password' => 'Mat-khau-hien-tai-1'])->save());

        $this->change($user, ['current_password' => 'Mat-khau-hien-tai-1', 'password' => 'Mat-khau-hien-tai-1', 'password_confirmation' => 'Mat-khau-hien-tai-1'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_authentication_is_required(): void
    {
        $this->spa()->postJson('/api/v1/auth/password', $this->valid())->assertUnauthorized();
    }

    public function test_a_platform_admin_can_change_their_own_password(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->change($admin, $this->valid())->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->platform(fn () => User::find($admin->id))->password));
    }

    // --- Mật khẩu tạm: bắt buộc đổi ------------------------------------------------------

    public function test_a_temporary_password_blocks_everything_except_changing_it(): void
    {
        [$tenant, $owner] = $this->makeTenant(user: ['must_change_password' => true]);
        $actingAs = fn () => $this->spa()->actingAs($owner);

        foreach (['/api/v1/branches', '/api/v1/users', '/api/v1/roles', '/api/v1/audit-logs', '/api/v1/auth/sessions', '/api/v1/permissions'] as $url) {
            $actingAs()->getJson($url)->assertForbidden()->assertJsonPath('code', 'password_change_required');
        }
        $actingAs()->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'Y'])->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // Vẫn dùng được: xem hồ sơ (để biết phải đổi mật khẩu) và đăng xuất.
        $actingAs()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->change($owner, $this->valid())->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->spa()->actingAs($this->platform(fn () => User::find($owner->id)))->getJson('/api/v1/branches')->assertOk();

        $this->assertTrue($this->lastAudit(['action' => 'password_changed'])->metadata['forced_change']);
    }

    public function test_logging_out_is_allowed_while_a_change_is_pending(): void
    {
        [, $owner] = $this->makeTenant(user: ['must_change_password' => true]);

        $this->spa()->actingAs($owner)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_the_change_endpoint_is_rate_limited(): void
    {
        config(['platform.rate_limits.sensitive' => 10]);
        [, $user] = $this->makeTenant();

        foreach (range(1, 10) as $i) {
            $this->change($user, $this->valid(['current_password' => 'sai-'.$i]))->assertStatus(422);
        }

        $this->change($user, $this->valid(['current_password' => 'sai-nua']))->assertStatus(429);
    }
}
