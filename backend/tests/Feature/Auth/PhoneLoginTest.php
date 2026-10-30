<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\Phone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Đăng nhập bằng email HOẶC số điện thoại; số điện thoại là định danh duy nhất toàn hệ thống. */
class PhoneLoginTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const PASSWORD = 'Mat-khau-cu-2026';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function login(string $login, string $password = self::PASSWORD)
    {
        return $this->freshBrowser()->spa()->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password]);
    }

    // --- chuẩn hóa -------------------------------------------------------------------------

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phoneCases(): array
    {
        return [
            'chuẩn' => ['0912345678', '0912345678'],
            'khoảng trắng' => ['0912 345 678', '0912345678'],
            'chấm' => ['0912.345.678', '0912345678'],
            'gạch ngang' => ['091-234-5678', '0912345678'],
            'ngoặc' => ['(091) 2345678', '0912345678'],
            '+84' => ['+84 912 345 678', '0912345678'],
            '84 không dấu cộng' => ['84912345678', '0912345678'],
            'cố định Hà Nội' => ['024 3825 1234', '02438251234'],
            'bao quanh bởi khoảng trắng' => ['  0912345678  ', '0912345678'],
            'quá ngắn' => ['091234', null],
            'quá dài' => ['091234567890123', null],
            'chứa chữ' => ['0912abc678', null],
            'không bắt đầu bằng 0' => ['912345678', null],
            'đầu số 01 không tồn tại' => ['0112345678', null],
            'email' => ['a@b.vn', null],
            'rỗng' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('phoneCases')]
    public function test_phone_normalization(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($raw));
    }

    public function test_login_identifier_tells_emails_from_phones_and_builds_a_stable_throttle_key(): void
    {
        $this->assertSame(['email', 'chu@example.com'], [LoginIdentifier::parse(' Chu@Example.com ')->type, LoginIdentifier::parse(' Chu@Example.com ')->value]);
        $this->assertSame(['phone', '0912345678'], [LoginIdentifier::parse('+84 912 345 678')->type, LoginIdentifier::parse('+84 912 345 678')->value]);
        $this->assertNull(LoginIdentifier::parse('khong-phai-gi'));
        $this->assertNull(LoginIdentifier::parse('a@b'));

        $this->assertSame(LoginIdentifier::throttleKey('0912 345 678'), LoginIdentifier::throttleKey('+84912345678'));
        $this->assertSame(LoginIdentifier::throttleKey('A@X.com'), LoginIdentifier::throttleKey('a@x.com'));
        $this->assertNotSame(LoginIdentifier::throttleKey('0912345678'), LoginIdentifier::throttleKey('0912345679'));
    }

    // --- đăng nhập -----------------------------------------------------------------------

    public function test_a_user_can_log_in_with_their_phone_written_in_any_common_format(): void
    {
        [, $user] = $this->makeTenant([], ['phone' => '091 234 5678', 'password' => self::PASSWORD]);

        $this->assertSame('0912345678', $this->platform(fn () => $user->fresh()->phone), 'số được lưu dạng chuẩn');

        foreach (['0912345678', '0912 345 678', '+84 912 345 678', '84912345678', '091-234-5678'] as $typed) {
            $this->login($typed)->assertOk()->assertJsonPath('data.id', $user->uuid)->assertJsonPath('data.phone', '0912345678');
        }
    }

    public function test_email_still_works_through_both_the_new_and_the_old_field_name(): void
    {
        [, $user] = $this->makeTenant(['name' => 'A'], ['email' => 'chu@example.com', 'password' => self::PASSWORD]);

        $this->login('Chu@Example.com')->assertOk()->assertJsonPath('data.id', $user->uuid);
        $this->freshBrowser()->spa()->postJson('/api/v1/auth/login', ['email' => 'chu@example.com', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_the_platform_admin_can_use_a_phone_too(): void
    {
        $admin = $this->makePlatformAdmin(['phone' => '0988000111', 'password' => self::PASSWORD]);

        $this->login('0988 000 111')->assertOk()->assertJsonPath('data.id', $admin->uuid)->assertJsonPath('data.is_platform_admin', true);
    }

    public function test_wrong_phone_and_wrong_password_look_identical_so_accounts_cannot_be_probed(): void
    {
        $this->makeTenant([], ['phone' => '0912345678', 'password' => self::PASSWORD]);

        $wrongPassword = $this->login('0912345678', 'sai-roi');
        $unknownPhone = $this->login('0999999999', 'sai-roi');

        $wrongPassword->assertStatus(401)->assertJsonPath('code', 'invalid_credentials')->assertJsonPath('detail', 'Tài khoản hoặc mật khẩu không đúng.');
        $this->assertSame($wrongPassword->json('detail'), $unknownPhone->json('detail'));
        $this->assertSame($wrongPassword->status(), $unknownPhone->status());
    }

    public function test_blocked_accounts_get_the_same_answers_by_phone(): void
    {
        [, $user] = $this->makeTenant([], ['phone' => '0912345678', 'password' => self::PASSWORD]);
        $this->platform(fn () => $user->forceFill(['status' => 'disabled'])->save());

        $this->login('0912345678')->assertForbidden()->assertJsonPath('code', 'account_disabled');
    }

    public function test_repeated_failures_are_throttled_per_phone_whatever_its_formatting(): void
    {
        $this->makeTenant([], ['phone' => '0912345678', 'password' => self::PASSWORD]);

        foreach (['0912345678', '0912 345 678', '+84912345678', '091-234-5678', '84912345678'] as $typed) {
            $this->login($typed, 'sai-roi')->assertUnauthorized();
        }

        // Lần thứ 6 với một cách viết khác vẫn bị chặn (cùng khóa giới hạn / tài khoản đã bị khóa tạm).
        $this->login('0912.345.678')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_input_that_is_neither_an_email_nor_a_phone_is_rejected_in_vietnamese(): void
    {
        $this->login('khong-phai-gi')->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.login.0', 'Vui lòng nhập email hoặc số điện thoại hợp lệ.');

        $this->login('123')->assertStatus(422)->assertJsonPath('errors.login.0', 'Vui lòng nhập email hoặc số điện thoại hợp lệ.');

        app()->setLocale('vi');
        $this->freshBrowser()->spa()->postJson('/api/v1/auth/login', [])->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'Email hoặc số điện thoại là bắt buộc.')
            ->assertJsonPath('errors.password.0', 'Mật khẩu là bắt buộc.');
    }

    public function test_failed_phone_logins_are_audited_with_the_normalized_number_and_never_the_password(): void
    {
        $this->login('+84 999 888 777', 'bi-mat-cua-toi')->assertUnauthorized();

        $log = $this->lastAudit(['module' => 'auth', 'action' => 'login_failed']);

        $this->assertSame('0999888777', $log->metadata['phone'] ?? null);
        $this->assertStringNotContainsString('bi-mat-cua-toi', json_encode($log->metadata));
    }

    // --- số điện thoại là định danh duy nhất -----------------------------------------------------

    public function test_creating_users_stores_the_normalized_number_and_rejects_numbers_already_in_use_anywhere(): void
    {
        [, $ownerA] = $this->makeTenant(['name' => 'A'], ['password' => self::PASSWORD]);
        [, $ownerB] = $this->makeTenant(['name' => 'B'], ['phone' => '0912345678', 'password' => self::PASSWORD]);

        $create = fn (array $body) => $this->freshBrowser()->spa()->actingAs($ownerA)->postJson('/api/v1/users', $body + ['name' => 'Nhân viên']);

        // Số của Tenant B (viết kiểu khác) không dùng lại được ở Tenant A; thông báo không lộ số thuộc ai.
        $create(['email' => 'nv1@a.vn', 'phone' => '+84 912 345 678'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'Số điện thoại này không khả dụng.');

        $create(['email' => 'nv2@a.vn', 'phone' => 'khong-phai-so'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'Số điện thoại không hợp lệ (ví dụ 0912 345 678).');

        $created = $create(['email' => 'nv3@a.vn', 'phone' => '0933 444 555'])->assertCreated()->assertJsonPath('data.phone', '0933444555');
        $this->assertNotNull($created->json('data.id'));

        // Chủ Tenant B không tạo trùng số vừa dùng ở Tenant A.
        $this->freshBrowser()->spa()->actingAs($ownerB)->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@b.vn', 'phone' => '0933-444-555'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Số điện thoại này không khả dụng.');
    }

    public function test_updating_a_phone_allows_keeping_your_own_and_clearing_it_but_not_taking_anothers(): void
    {
        [$tenant, $owner] = $this->makeTenant(['name' => 'A'], ['phone' => '0911111111', 'password' => self::PASSWORD]);
        $other = $this->makeUserIn($tenant, [Role::OWNER], attributes: ['phone' => '0922222222']);

        $update = fn ($user, $phone) => $this->freshBrowser()->spa()->actingAs($owner)->patchJson("/api/v1/users/{$user->uuid}", ['phone' => $phone]);

        $update($owner, '0911 111 111')->assertOk()->assertJsonPath('data.phone', '0911111111'); // giữ nguyên số của chính mình
        $update($owner, '0922222222')->assertStatus(422)->assertJsonPath('errors.phone.0', 'Số điện thoại này không khả dụng.');
        $update($other, '0944 555 666')->assertOk()->assertJsonPath('data.phone', '0944555666');
        $update($other, null)->assertOk()->assertJsonPath('data.phone', null);

        // Số đã nhả ra thì người khác dùng được.
        $update($owner, '0944555666')->assertOk();
    }

    public function test_tenant_onboarding_normalizes_the_owner_phone_and_the_owner_can_log_in_with_it(): void
    {
        $admin = $this->makePlatformAdmin(['password' => self::PASSWORD]);

        $response = $this->freshBrowser()->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', [
            'tenant' => ['name' => 'Cửa hàng Mới'],
            'owner' => ['name' => 'Chủ Mới', 'email' => 'chu@moi.vn', 'phone' => '+84 977 123 456'],
        ])->assertCreated();

        $this->login('0977 123 456', $response->json('temporary_password'))->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->freshBrowser()->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', [
            'tenant' => ['name' => 'Cửa hàng Khác'],
            'owner' => ['name' => 'Chủ Khác', 'email' => 'chu@khac.vn', 'phone' => '0977123456'],
        ])->assertStatus(422)->assertJsonValidationErrors(['owner.phone' => 'Số điện thoại này không khả dụng.']);
    }

    public function test_the_database_itself_refuses_two_accounts_with_the_same_phone(): void
    {
        $this->makeTenant([], ['phone' => '0912345678']);
        [, $second] = $this->makeTenant();

        $this->expectException(QueryException::class);

        $this->platform(fn () => DB::table('users')->where('id', $second->id)->update(['phone' => '0912345678']));
    }
}
