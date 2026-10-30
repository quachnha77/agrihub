<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/**
 * Mỗi doanh nghiệp một subdomain nên số điện thoại chỉ cần duy nhất TRONG doanh nghiệp: hai doanh nghiệp có thể có cùng một số,
 * và đăng nhập bằng số điện thoại luôn chọn đúng tài khoản theo địa chỉ đang truy cập.
 */
class PhonePerTenantTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const PASSWORD_A = 'Mat-khau-cua-A-2026';

    private const PASSWORD_B = 'Mat-khau-cua-B-2026';

    private const SHARED = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function enable(): void
    {
        config(['tenancy.base_domain' => 'agrihub.test', 'tenancy.platform_labels' => ['admin']]);
    }

    private function login(string $host, string $login, string $password)
    {
        return $this->freshBrowser()->spa()->postJson("http://{$host}/api/v1/auth/login", ['login' => $login, 'password' => $password]);
    }

    /** @return array{0: User, 1: User} chủ của hai doanh nghiệp dùng chung một số điện thoại */
    private function twoBusinessesSharingAPhone(): array
    {
        [, $a] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'phone' => self::SHARED, 'password' => self::PASSWORD_A]);
        [, $b] = $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => 'b@b.vn', 'phone' => self::SHARED, 'password' => self::PASSWORD_B]);

        return [$a, $b];
    }

    public function test_the_same_phone_logs_in_to_the_right_account_at_each_address(): void
    {
        $this->enable();
        [$a, $b] = $this->twoBusinessesSharingAPhone();

        $this->login('nong-phat.agrihub.test', '0912 345 678', self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);
        $this->login('vat-tu-xanh.agrihub.test', '+84912345678', self::PASSWORD_B)->assertOk()->assertJsonPath('data.id', $b->uuid);

        // Mật khẩu của người kia không mở được tài khoản ở địa chỉ này.
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_B)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_phone_that_belongs_to_another_business_gets_no_hint_about_where_it_belongs(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'phone' => '0911111111', 'password' => self::PASSWORD_A]);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);

        // Số điện thoại có thể trùng giữa các doanh nghiệp nên không thể nói "bạn thuộc doanh nghiệp khác": chỉ báo chung.
        $this->login('vat-tu-xanh.agrihub.test', '0911111111', self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        // Email thì duy nhất toàn hệ thống nên vẫn chỉ đường khi mật khẩu đúng.
        $this->login('vat-tu-xanh.agrihub.test', 'a@a.vn', self::PASSWORD_A)->assertForbidden()->assertJsonPath('code', 'wrong_host');
    }

    public function test_tenant_phones_are_not_found_at_the_admin_address_and_admin_phones_not_at_a_business(): void
    {
        $this->enable();
        [$a] = $this->twoBusinessesSharingAPhone();
        $admin = $this->makePlatformAdmin(['email' => 'root@agrihub.test', 'phone' => self::SHARED, 'password' => 'Mat-khau-admin-2026']);

        $this->login('admin.agrihub.test', self::SHARED, 'Mat-khau-admin-2026')->assertOk()->assertJsonPath('data.id', $admin->uuid);
        $this->login('admin.agrihub.test', self::SHARED, self::PASSWORD_A)->assertUnauthorized();
        $this->login('nong-phat.agrihub.test', self::SHARED, 'Mat-khau-admin-2026')->assertUnauthorized();
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);
    }

    public function test_without_subdomains_a_shared_phone_is_ambiguous_so_it_cannot_log_in_but_a_unique_one_can(): void
    {
        [$a] = $this->twoBusinessesSharingAPhone();
        $this->platform(fn () => $a->forceFill(['phone' => '0955555555'])->save());

        // Chưa bật subdomain: số duy nhất vẫn đăng nhập được...
        $this->login('localhost', '0955 555 555', self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);

        // ...còn số dùng chung ở hai doanh nghiệp thì không biết là ai: dùng email hoặc subdomain.
        $this->platform(fn () => $a->forceFill(['phone' => self::SHARED])->save());
        $this->login('localhost', self::SHARED, self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->login('localhost', 'a@a.vn', self::PASSWORD_A)->assertOk();
    }

    public function test_failures_on_one_business_do_not_lock_or_throttle_the_same_phone_of_another(): void
    {
        $this->enable();
        [, $b] = $this->twoBusinessesSharingAPhone();

        foreach (range(1, 5) as $_) {
            $this->login('nong-phat.agrihub.test', self::SHARED, 'sai-roi')->assertUnauthorized();
        }
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_A)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        // Cùng số, cùng người gõ, nhưng là tài khoản của doanh nghiệp khác: không bị ảnh hưởng.
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_B)->assertOk()->assertJsonPath('data.id', $b->uuid);
    }

    public function test_an_owner_added_by_onboarding_may_share_a_phone_with_another_business_and_logs_in_at_their_own_address(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'da-co'], ['phone' => self::SHARED]);
        $admin = $this->makePlatformAdmin(['password' => self::PASSWORD_A]);

        $created = $this->freshBrowser()->spa()->actingAs($admin)->postJson('http://admin.agrihub.test/api/v1/platform/tenants', [
            'tenant' => ['name' => 'Cửa hàng Mới', 'slug' => 'cua-hang-moi'],
            'owner' => ['name' => 'Chủ Mới', 'email' => 'chu@moi.vn', 'phone' => '091 234 5678'],
        ])->assertCreated();

        $this->login('cua-hang-moi.agrihub.test', self::SHARED, $created->json('temporary_password'))->assertOk()
            ->assertJsonPath('data.email', 'chu@moi.vn')->assertJsonPath('data.must_change_password', true);
    }
}
