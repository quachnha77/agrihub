<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Lỗi 422 trả về tiếng Việt (giao diện hiển thị nguyên văn các thông báo này). */
class ValidationLanguageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('vi');
    }

    public function test_required_and_format_errors_are_vietnamese(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', [])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'Email là bắt buộc.')
            ->assertJsonPath('errors.password.0', 'Mật khẩu là bắt buộc.');

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'khong-phai-email', 'password' => 'x'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email phải là địa chỉ email hợp lệ.');
    }

    public function test_password_rules_and_confirmation_are_vietnamese(): void
    {
        [, $owner] = $this->makeTenant([], ['password' => 'Mat-khau-cu-2026']);

        $this->spa()->actingAs($owner)->postJson('/api/v1/auth/password', [
            'current_password' => 'Mat-khau-cu-2026', 'password' => 'ngan', 'password_confirmation' => 'khac',
        ])->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Xác nhận mật khẩu không khớp.')
            ->assertJsonMissingPath('errors.current_password');
    }
}
