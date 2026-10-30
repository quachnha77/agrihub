<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_user_can_login_and_receives_profile_with_tenant_and_branches(): void
    {
        [$tenant, $user, $branch] = $this->makeTenant(user: ['email' => 'Chu@Example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'chu@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->uuid)
            ->assertJsonPath('data.email', 'chu@example.com')
            ->assertJsonPath('data.tenant.id', $tenant->uuid)
            ->assertJsonPath('data.branches.0.id', $branch->uuid)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($this->platform(fn () => $user->fresh()));
        $this->assertNotNull($this->platform(fn () => $user->fresh()->last_login_at));
    }

    public function test_wrong_password_and_unknown_email_return_identical_errors(): void
    {
        [, $user] = $this->makeTenant();

        $wrong = $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'sai-roi']);
        $unknown = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'khong-ton-tai@example.com', 'password' => 'sai-roi']);

        $wrong->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $unknown->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->assertSame($wrong->json('detail'), $unknown->json('detail'));
        $this->assertGuest();
    }

    public function test_account_locks_after_repeated_failures_even_with_correct_password(): void
    {
        [, $user] = $this->makeTenant();
        $this->platform(fn () => $user->forceFill(['failed_login_attempts' => 4])->save());

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'sai'])
            ->assertUnauthorized();

        $this->assertTrue($this->platform(fn () => $user->fresh()->isLocked()));

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
        $this->assertGuest();
    }

    public function test_lock_expires(): void
    {
        [, $user] = $this->makeTenant();
        $this->platform(fn () => $user->forceFill(['locked_until' => now()->subMinute(), 'failed_login_attempts' => 0])->save());

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        [, $user] = $this->makeTenant();

        foreach (range(1, 5) as $i) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ai-do@example.com', 'password' => 'x'.$i])->assertUnauthorized();
        }

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ai-do@example.com', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_disabled_user_cannot_login(): void
    {
        [, $user] = $this->makeTenant(user: ['status' => User::STATUS_DISABLED]);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_disabled');
    }

    public function test_user_of_suspended_tenant_cannot_login(): void
    {
        [, $user] = $this->makeTenant(tenant: ['status' => 'suspended']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
    }

    public function test_platform_admin_can_login_without_tenant(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.is_platform_admin', true)
            ->assertJsonPath('data.tenant', null);
    }

    public function test_login_requires_a_stateful_spa_request(): void
    {
        [, $user] = $this->makeTenant();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(400)->assertJsonPath('code', 'stateful_request_required');
    }

    public function test_login_validates_input(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'khong-phai-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_me_requires_authentication_and_returns_profile(): void
    {
        [, $user] = $this->makeTenant();

        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->spa()->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_ends_the_session(): void
    {
        [, $user] = $this->makeTenant();

        $this->spa()->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
    }
}
