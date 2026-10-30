<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CreatePlatformAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_it_creates_a_platform_admin_without_tenant(): void
    {
        $this->artisan('agrihub:create-platform-admin', ['email' => 'Root@AgriHub.vn'])
            ->expectsQuestion('Mật khẩu (tối thiểu 12 ký tự)', 'mat-khau-rat-dai-123')
            ->expectsQuestion('Nhập lại mật khẩu', 'mat-khau-rat-dai-123')
            ->assertSuccessful();

        $user = $this->platform(fn () => User::where('email', 'root@agrihub.vn')->first());
        $this->assertTrue($user->is_platform_admin);
        $this->assertNull($user->tenant_id);
    }

    public function test_it_rejects_short_passwords(): void
    {
        $this->artisan('agrihub:create-platform-admin', ['email' => 'root@agrihub.vn'])
            ->expectsQuestion('Mật khẩu (tối thiểu 12 ký tự)', 'ngan')
            ->expectsQuestion('Nhập lại mật khẩu', 'ngan')
            ->assertFailed();

        $this->assertSame(0, $this->platform(fn () => User::count()));
    }

    public function test_it_refuses_duplicate_email(): void
    {
        $this->makePlatformAdmin(['email' => 'root@agrihub.vn']);

        $this->artisan('agrihub:create-platform-admin', ['email' => 'root@agrihub.vn'])
            ->expectsQuestion('Mật khẩu (tối thiểu 12 ký tự)', 'mat-khau-rat-dai-123')
            ->expectsQuestion('Nhập lại mật khẩu', 'mat-khau-rat-dai-123')
            ->assertFailed();
    }
}
