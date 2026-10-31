<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Notifications\Auth\AccountReminder;
use App\Notifications\Auth\ResetPasswordLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mỗi doanh nghiệp có tài khoản riêng: email chỉ cần duy nhất TRONG doanh nghiệp. Hai doanh nghiệp được dùng chung một email,
 * đăng nhập / quên mật khẩu / quên tài khoản luôn chọn đúng tài khoản theo địa chỉ (subdomain) đang truy cập.
 */
class EmailPerTenantTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PASSWORD_A = 'Mat-khau-cua-A-2026';

    private const PASSWORD_B = 'Mat-khau-cua-B-2026';

    private const NEW = 'Mat-khau-moi-2027-x';

    private const SHARED = 'ke.toan@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
        Notification::fake();
    }

    private function enable(): void
    {
        config(['tenancy.base_domain' => 'agrihub.test', 'tenancy.platform_labels' => ['admin']]);
    }

    private function login(string $host, string $login, string $password)
    {
        return $this->freshBrowser()->spa()->postJson("http://{$host}/api/v1/auth/login", ['login' => $login, 'password' => $password]);
    }

    /** @return array{0: User, 1: User} chủ của hai doanh nghiệp dùng chung một email */
    private function twoBusinessesSharingAnEmail(): array
    {
        [, $a] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => self::SHARED, 'phone' => '0911111111', 'password' => self::PASSWORD_A]);
        [, $b] = $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => self::SHARED, 'phone' => '0922222222', 'password' => self::PASSWORD_B]);

        return [$a, $b];
    }

    private function forgot(string $path, string $host, string $login)
    {
        return $this->postJson("http://{$host}/api/v1/auth/{$path}", ['login' => $login])->assertStatus(202);
    }

    /** @return array{token: string, email: string} */
    private function lastLink(User $user): array
    {
        parse_str((string) parse_url(Notification::sent($user, ResetPasswordLink::class)->last()->url, PHP_URL_QUERY), $query);

        return ['token' => $query['token'], 'email' => $query['email']];
    }

    private function reset(string $host, array $link, string $password = self::NEW)
    {
        return $this->postJson("http://{$host}/api/v1/auth/reset-password", $link + ['password' => $password, 'password_confirmation' => $password]);
    }

    // --- đăng nhập ------------------------------------------------------------------------------

    public function test_the_same_email_logs_in_to_the_right_account_at_each_address(): void
    {
        $this->enable();
        [$a, $b] = $this->twoBusinessesSharingAnEmail();

        $this->login('nong-phat.agrihub.test', 'KE.TOAN@gmail.com', self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_B)->assertOk()->assertJsonPath('data.id', $b->uuid);

        // Mật khẩu của doanh nghiệp kia không mở được tài khoản ở địa chỉ này.
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_B)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_shared_email_gives_no_hint_at_an_address_where_it_does_not_belong(): void
    {
        $this->enable();
        $this->twoBusinessesSharingAnEmail();
        $this->makeTenant(['slug' => 'thu-ba']);

        // Hai nơi dùng email này nên không thể chỉ đường: báo chung, kể cả khi mật khẩu đúng.
        $this->login('thu-ba.agrihub.test', self::SHARED, self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_an_email_used_by_a_single_business_still_points_to_the_right_address(): void
    {
        $this->enable();
        $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn', 'password' => self::PASSWORD_A]);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);

        $this->login('vat-tu-xanh.agrihub.test', 'chu@nongphat.vn', self::PASSWORD_A)->assertForbidden()
            ->assertJsonPath('code', 'wrong_host')->assertJsonPath('login_url', 'http://nong-phat.agrihub.test');
    }

    public function test_an_admin_and_a_business_user_may_share_an_email_and_each_logs_in_at_their_own_address(): void
    {
        $this->enable();
        [$a] = $this->twoBusinessesSharingAnEmail();
        $admin = $this->makePlatformAdmin(['email' => self::SHARED, 'password' => 'Mat-khau-admin-2026']);

        $this->login('admin.agrihub.test', self::SHARED, 'Mat-khau-admin-2026')->assertOk()->assertJsonPath('data.id', $admin->uuid);
        $this->login('admin.agrihub.test', self::SHARED, self::PASSWORD_A)->assertUnauthorized();
        $this->login('nong-phat.agrihub.test', self::SHARED, 'Mat-khau-admin-2026')->assertUnauthorized();
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);
    }

    public function test_without_subdomains_a_shared_email_is_ambiguous_so_it_cannot_log_in(): void
    {
        [$a] = $this->twoBusinessesSharingAnEmail();

        $this->login('localhost', self::SHARED, self::PASSWORD_A)->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        // Số điện thoại duy nhất vẫn dùng được thay thế.
        $this->login('localhost', '0911111111', self::PASSWORD_A)->assertOk()->assertJsonPath('data.id', $a->uuid);
    }

    public function test_failures_on_one_business_do_not_lock_or_throttle_the_same_email_of_another(): void
    {
        $this->enable();
        [, $b] = $this->twoBusinessesSharingAnEmail();

        foreach (range(1, 5) as $_) {
            $this->login('nong-phat.agrihub.test', self::SHARED, 'sai-roi')->assertUnauthorized();
        }
        $this->login('nong-phat.agrihub.test', self::SHARED, self::PASSWORD_A)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_B)->assertOk()->assertJsonPath('data.id', $b->uuid);
    }

    // --- tạo tài khoản ------------------------------------------------------------------------------

    /** Chủ doanh nghiệp thêm một người dùng, gọi tại địa chỉ riêng của doanh nghiệp đó. */
    private function addUser(User $owner, string $host, string $email)
    {
        return $this->spa()->actingAs($owner)->postJson("http://{$host}/api/v1/users", ['name' => 'Nhân Viên Mới', 'email' => $email, 'password' => 'Nhan-vien-moi-2026-zz']);
    }

    public function test_a_business_may_add_a_user_whose_email_is_used_by_another_business_but_not_twice_inside_itself(): void
    {
        $this->enable();
        $this->twoBusinessesSharingAnEmail();
        [, $owner] = $this->makeTenant(['slug' => 'thu-ba']);

        $this->addUser($owner, 'thu-ba.agrihub.test', self::SHARED)->assertCreated();
        $this->addUser($owner, 'thu-ba.agrihub.test', 'KE.TOAN@gmail.com')->assertStatus(422)->assertJsonPath('errors.email.0', 'Email này không khả dụng.');
    }

    public function test_the_email_of_a_deleted_user_stays_reserved_inside_its_own_business_only(): void
    {
        $this->enable();
        [$tenant, $owner] = $this->makeTenant(['slug' => 'nong-phat']);
        $gone = $this->makeUserIn($tenant, [], attributes: ['email' => 'da-xoa@example.com']);
        $this->spa()->actingAs($owner)->deleteJson('http://nong-phat.agrihub.test/api/v1/users/'.$gone->uuid)->assertNoContent();
        [, $other] = $this->makeTenant(['slug' => 'vat-tu-xanh']);

        $this->addUser($owner, 'nong-phat.agrihub.test', 'da-xoa@example.com')->assertStatus(422);
        $this->addUser($other, 'vat-tu-xanh.agrihub.test', 'da-xoa@example.com')->assertCreated();
    }

    public function test_without_subdomains_an_email_stays_unique_everywhere_because_the_address_cannot_tell_businesses_apart(): void
    {
        [, $ownerA] = $this->makeTenant();
        $this->makeTenant(user: ['email' => 'da-co@other.test']);

        $this->addUser($ownerA, 'localhost', 'DA-CO@other.test')->assertStatus(422)->assertJsonPath('errors.email.0', 'Email này không khả dụng.');
    }

    public function test_onboarding_allows_the_owner_to_use_an_email_of_another_business_only_when_subdomains_are_on(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->makeTenant(['slug' => 'da-co'], ['email' => self::SHARED]);
        $payload = fn (string $slug) => [
            'tenant' => ['name' => 'Cửa hàng Mới', 'slug' => $slug],
            'owner' => ['name' => 'Chủ Mới', 'email' => self::SHARED, 'phone' => '0933333333'],
        ];

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $payload('cua-hang-1'))->assertStatus(422)->assertJsonStructure(['errors' => ['owner.email']]);

        $this->enable();
        $created = $this->spa()->actingAs($admin)->postJson('http://admin.agrihub.test/api/v1/platform/tenants', $payload('cua-hang-2'))->assertCreated();

        $this->login('cua-hang-2.agrihub.test', self::SHARED, $created->json('temporary_password'))->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_the_database_refuses_two_accounts_with_the_same_email_in_one_business_but_not_across_businesses(): void
    {
        [$tenant] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => self::SHARED]);
        $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => self::SHARED]); // khác doanh nghiệp: được

        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::transaction(fn () => $this->makeUserIn($tenant, [], attributes: ['email' => self::SHARED])));
    }

    // --- quên mật khẩu / quên tài khoản ------------------------------------------------------------

    public function test_a_reset_link_is_sent_only_to_the_account_at_the_address_where_it_was_requested(): void
    {
        $this->enable();
        [$a, $b] = $this->twoBusinessesSharingAnEmail();

        $this->forgot('forgot-password', 'vat-tu-xanh.agrihub.test', self::SHARED);

        Notification::assertSentTo($b, ResetPasswordLink::class, fn ($n) => str_starts_with($n->url, 'http://vat-tu-xanh.agrihub.test/reset-password?'));
        Notification::assertNotSentTo($a, ResetPasswordLink::class);
    }

    public function test_two_businesses_with_the_same_email_can_both_have_a_pending_reset_and_each_token_only_opens_its_own_account(): void
    {
        $this->enable();
        [$a, $b] = $this->twoBusinessesSharingAnEmail();

        $this->forgot('forgot-password', 'nong-phat.agrihub.test', self::SHARED);
        $this->forgot('forgot-password', 'vat-tu-xanh.agrihub.test', self::SHARED);
        $linkA = $this->lastLink($a);
        $linkB = $this->lastLink($b);

        // Cùng email nhưng mã của bên này không dùng được cho bên kia.
        $this->reset('vat-tu-xanh.agrihub.test', $linkA)->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        $this->reset('nong-phat.agrihub.test', $linkB)->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

        // Mỗi bên đặt lại được tài khoản của mình, không ảnh hưởng bên kia.
        $this->reset('nong-phat.agrihub.test', $linkA)->assertOk();
        $this->login('nong-phat.agrihub.test', self::SHARED, self::NEW)->assertOk();
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, self::PASSWORD_B)->assertOk();

        $this->reset('vat-tu-xanh.agrihub.test', $linkB, 'Mat-khau-khac-2028-y')->assertOk();
        $this->login('vat-tu-xanh.agrihub.test', self::SHARED, 'Mat-khau-khac-2028-y')->assertOk();
        $this->login('nong-phat.agrihub.test', self::SHARED, self::NEW)->assertOk();
    }

    public function test_a_reset_link_works_only_once_even_with_a_shared_email(): void
    {
        $this->enable();
        [$a] = $this->twoBusinessesSharingAnEmail();
        $this->forgot('forgot-password', 'nong-phat.agrihub.test', self::SHARED);
        $link = $this->lastLink($a);

        $this->reset('nong-phat.agrihub.test', $link)->assertOk();
        $this->reset('nong-phat.agrihub.test', $link, 'Mat-khau-khac-2028-y')->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_forgetting_the_account_by_phone_finds_the_account_of_that_business_even_when_the_email_is_shared(): void
    {
        $this->enable();
        [$a, $b] = $this->twoBusinessesSharingAnEmail();

        $this->forgot('forgot-account', 'nong-phat.agrihub.test', '091 111 1111');

        Notification::assertSentTo($a, AccountReminder::class);
        Notification::assertNotSentTo($b, AccountReminder::class);
    }
}
