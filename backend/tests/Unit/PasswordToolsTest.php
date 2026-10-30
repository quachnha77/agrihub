<?php

namespace Tests\Unit;

use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\TempPassword;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordToolsTest extends TestCase
{
    public function test_temporary_passwords_are_long_random_and_meet_the_policy(): void
    {
        $seen = [];

        foreach (range(1, 200) as $i) {
            $password = TempPassword::generate();
            $seen[$password] = true;

            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password, 'Tránh ký tự dễ nhầm');
            $this->assertTrue($this->passes($password), $password);
        }

        $this->assertCount(200, $seen, 'Không trùng lặp');
    }

    public function test_temporary_password_length_has_a_floor(): void
    {
        $this->assertSame(12, strlen(TempPassword::generate(4)));
        $this->assertSame(24, strlen(TempPassword::generate(24)));
    }

    public function test_policy_accepts_and_rejects_the_expected_passwords(): void
    {
        $this->assertTrue($this->passes('Mat-khau-manh-2026'));
        $this->assertTrue($this->passes('1234abcd5678'));

        $this->assertFalse($this->passes('ngan1'), 'quá ngắn');
        $this->assertFalse($this->passes('khongcoso-nao-ca'), 'thiếu số');
        $this->assertFalse($this->passes('123456789012'), 'thiếu chữ');
        $this->assertFalse($this->passes('PASSWORD1234'), 'nằm trong danh sách yếu (không phân biệt hoa thường)');
        $this->assertFalse($this->passes(str_repeat('a1', 70)), 'quá dài');
    }

    public function test_policy_rejects_passwords_containing_the_login_name(): void
    {
        $this->assertFalse($this->passes('xNguyenVanA2026', 'nguyenvana@example.com'));
        $this->assertTrue($this->passes('xAnh2026-Lucky', 'anh@example.com'), 'Tên đăng nhập quá ngắn (< 4) thì không áp dụng');
        $this->assertTrue($this->passes('Mat-khau-manh-2026', 'nguyenvana@example.com'));
    }

    public function test_the_email_can_be_resolved_lazily(): void
    {
        $rules = PasswordPolicy::rules(fn () => 'tranvanb@example.com');

        $this->assertTrue(Validator::make(['p' => 'Mat-khau-manh-2026'], ['p' => $rules])->passes());
        $this->assertFalse(Validator::make(['p' => 'abcTranVanB123'], ['p' => $rules])->passes());
    }

    private function passes(string $password, ?string $email = null): bool
    {
        return Validator::make(['p' => $password], ['p' => PasswordPolicy::rules($email)])->passes();
    }
}
