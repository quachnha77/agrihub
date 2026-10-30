<?php

namespace Tests\Feature\Auth;

use App\Jobs\Auth\SendRecoveryEmail;
use App\Models\User;
use App\Notifications\Auth\AccountReminder;
use App\Notifications\Auth\PasswordWasReset;
use App\Notifications\Auth\ResetPasswordLink;
use App\Services\Auth\PasswordRecovery;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginScope;
use App\Support\Tenancy\HostTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Quên mật khẩu / quên tài khoản: chỉ đi qua email của chính tài khoản, không lộ tài khoản có tồn tại hay không. */
class PasswordRecoveryTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const OLD = 'Mat-khau-cu-2026';

    private const NEW = 'Mat-khau-moi-2027-x';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function enableSubdomains(): void
    {
        config(['tenancy.base_domain' => 'agrihub.test', 'tenancy.platform_labels' => ['admin']]);
    }

    private function ask(string $path, string $login, string $host = 'localhost')
    {
        return $this->postJson("http://{$host}/api/v1/auth/{$path}", ['login' => $login]);
    }

    /** @return array{token: string, email: string, url: string} */
    private function lastLink(User $user): array
    {
        $url = Notification::sent($user, ResetPasswordLink::class)->last()->url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['token' => $query['token'], 'email' => $query['email'], 'url' => $url];
    }

    private function reset(array $body)
    {
        return $this->postJson('/api/v1/auth/reset-password', $body);
    }

    private function resetWith(User $user, string $password = self::NEW)
    {
        $link = $this->lastLink($user);

        return $this->reset(['email' => $link['email'], 'token' => $link['token'], 'password' => $password, 'password_confirmation' => $password]);
    }

    // --- quên mật khẩu: phản hồi và người nhận ------------------------------------------------------

    public function test_an_existing_and_an_unknown_account_get_exactly_the_same_answer(): void
    {
        $this->makeTenant([], ['email' => 'co@a.vn', 'phone' => '0912345678']);

        $known = $this->ask('forgot-password', 'co@a.vn')->assertStatus(202);
        $unknown = $this->ask('forgot-password', 'khong-co@a.vn')->assertStatus(202);
        $knownPhone = $this->ask('forgot-password', '0912 345 678')->assertStatus(202);
        $unknownPhone = $this->ask('forgot-password', '0999999999')->assertStatus(202);

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->getContent(), $knownPhone->getContent());
        $this->assertSame($known->getContent(), $unknownPhone->getContent());
        $this->assertStringContainsString('Nếu thông tin khớp', $known->json('message'));

        Notification::assertSentToTimes($this->platform(fn () => User::where('email', 'co@a.vn')->first()), ResetPasswordLink::class, 1);
    }

    public function test_the_request_only_queues_work_so_response_time_does_not_reveal_accounts(): void
    {
        Queue::fake();
        $this->makeTenant([], ['email' => 'co@a.vn']);

        $this->ask('forgot-password', 'co@a.vn')->assertStatus(202);
        $this->ask('forgot-password', 'khong-co@a.vn')->assertStatus(202);

        Queue::assertPushed(SendRecoveryEmail::class, 2);
        Notification::assertNothingSent(); // chưa tra cứu gì trong request
    }

    public function test_a_queue_failure_does_not_change_the_answer(): void
    {
        config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'khong-ton-tai']]);
        $this->makeTenant([], ['email' => 'co@a.vn']);

        $this->ask('forgot-password', 'co@a.vn')->assertStatus(202)->assertJsonStructure(['message']);
    }

    public function test_input_that_is_neither_an_email_nor_a_phone_is_rejected(): void
    {
        $this->ask('forgot-password', 'khong-phai-gi')->assertStatus(422)->assertJsonPath('errors.login.0', 'Vui lòng nhập email hoặc số điện thoại hợp lệ.');
        $this->postJson('/api/v1/auth/forgot-account', [])->assertStatus(422)->assertJsonValidationErrors('login');
    }

    public function test_the_link_points_to_the_users_own_business_address_and_asks_for_a_token_and_email(): void
    {
        $this->enableSubdomains();
        [, $owner] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn']);

        $this->ask('forgot-password', 'chu@nongphat.vn', 'nong-phat.agrihub.test')->assertStatus(202);

        $link = $this->lastLink($owner);
        $this->assertStringStartsWith('http://nong-phat.agrihub.test/reset-password?token=', $link['url']);
        $this->assertSame('chu@nongphat.vn', $link['email']);
        $this->assertGreaterThanOrEqual(40, strlen($link['token']));
        Notification::assertSentTo($owner, ResetPasswordLink::class, fn ($n) => $n->minutes === 30);
    }

    public function test_asking_from_the_wrong_address_by_email_still_sends_the_link_for_the_right_address(): void
    {
        $this->enableSubdomains();
        [, $owner] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn']);
        $this->makeTenant(['slug' => 'vat-tu-xanh']);

        $this->ask('forgot-password', 'chu@nongphat.vn', 'vat-tu-xanh.agrihub.test')->assertStatus(202);

        $this->assertStringStartsWith('http://nong-phat.agrihub.test/reset-password', $this->lastLink($owner)['url']);
    }

    public function test_a_phone_is_looked_up_inside_the_business_at_that_address_and_the_mail_goes_to_the_account_email(): void
    {
        $this->enableSubdomains();
        [, $a] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'a@a.vn', 'phone' => '0912345678']);
        [, $b] = $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => 'b@b.vn', 'phone' => '0912345678']);

        $this->ask('forgot-password', '091 234 5678', 'vat-tu-xanh.agrihub.test')->assertStatus(202);

        Notification::assertSentTo($b, ResetPasswordLink::class);
        Notification::assertNotSentTo($a, ResetPasswordLink::class);
        $this->assertStringStartsWith('http://vat-tu-xanh.agrihub.test/', $this->lastLink($b)['url']);
    }

    public function test_a_shared_phone_without_subdomains_is_ambiguous_so_nothing_is_sent(): void
    {
        $this->makeTenant([], ['email' => 'a@a.vn', 'phone' => '0912345678']);
        $this->makeTenant([], ['email' => 'b@b.vn', 'phone' => '0912345678']);

        $this->ask('forgot-password', '0912345678')->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_the_link_is_built_from_configuration_never_from_the_request_host(): void
    {
        config(['tenancy.fallback_url' => 'https://app.agrihub.test']);
        [, $owner] = $this->makeTenant([], ['email' => 'chu@a.vn']);

        $this->ask('forgot-password', 'chu@a.vn', 'evil.example')->assertStatus(202);

        $url = $this->lastLink($owner)['url'];
        $this->assertStringStartsWith('https://app.agrihub.test/reset-password?', $url);
        $this->assertStringNotContainsString('evil.example', $url);
    }

    public function test_the_super_admin_gets_a_link_to_the_admin_address(): void
    {
        $this->enableSubdomains();
        $admin = $this->makePlatformAdmin(['email' => 'root@agrihub.test']);

        $this->ask('forgot-password', 'root@agrihub.test', 'admin.agrihub.test')->assertStatus(202);

        $this->assertStringStartsWith('http://admin.agrihub.test/reset-password?', $this->lastLink($admin)['url']);
    }

    public function test_disabled_accounts_receive_nothing_but_get_the_same_answer(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'khoa@a.vn']);
        $this->platform(fn () => $user->forceFill(['status' => 'disabled'])->save());

        $this->ask('forgot-password', 'khoa@a.vn')->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertSame('account_disabled', $this->lastAudit(['module' => 'auth', 'action' => 'password_reset_requested'])->reason);
    }

    public function test_asking_twice_in_a_row_sends_one_mail_and_a_mailbox_cannot_be_flooded_within_an_hour(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn']);
        $recovery = app(PasswordRecovery::class);
        $identifier = LoginIdentifier::parse('chu@a.vn');
        $scope = new LoginScope(HostTenant::UNRESTRICTED);

        $recovery->sendResetLink($identifier, $scope);
        $recovery->sendResetLink($identifier, $scope); // trong vòng 60 giây: bị giãn cách
        Notification::assertSentToTimes($user, ResetPasswordLink::class, 1);

        foreach (range(1, 4) as $_) {
            DB::table('password_reset_tokens')->delete(); // bỏ giãn cách 60 giây để chỉ còn giới hạn theo giờ
            $recovery->sendResetLink($identifier, $scope);
        }

        Notification::assertSentToTimes($user, ResetPasswordLink::class, 3);
        $this->assertSame('rate_limited', $this->lastAudit(['module' => 'auth', 'action' => 'password_reset_requested'])->reason);
    }

    public function test_requests_are_throttled_per_account_and_address(): void
    {
        $this->makeTenant([], ['email' => 'chu@a.vn']);

        foreach (range(1, 3) as $_) {
            $this->ask('forgot-password', 'chu@a.vn')->assertStatus(202);
        }

        $this->ask('forgot-password', 'chu@a.vn')->assertStatus(429);
        $this->ask('forgot-password', 'nguoi-khac@a.vn')->assertStatus(202);
    }

    // --- quên tài khoản --------------------------------------------------------------------------

    public function test_the_account_reminder_tells_the_user_where_and_how_to_log_in(): void
    {
        $this->enableSubdomains();
        [, $owner] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát', 'slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn', 'phone' => '0912345678']);

        // Quên cả địa chỉ: hỏi từ tên miền gốc, chỉ nhớ email.
        $this->ask('forgot-account', 'chu@nongphat.vn', 'agrihub.test')->assertStatus(202);

        Notification::assertSentTo($owner, AccountReminder::class, function (AccountReminder $n) {
            return $n->loginUrl === 'http://nong-phat.agrihub.test'
                && $n->businessName === 'Cửa hàng Nông Phát'
                && $n->email === 'chu@nongphat.vn'
                && $n->maskedPhone === '091****678';
        });
        Notification::assertNotSentTo($owner, ResetPasswordLink::class);
    }

    public function test_the_account_reminder_can_be_found_by_phone_at_the_business_address(): void
    {
        $this->enableSubdomains();
        [, $owner] = $this->makeTenant(['slug' => 'nong-phat'], ['email' => 'chu@nongphat.vn', 'phone' => '0912345678']);
        $this->makeTenant(['slug' => 'vat-tu-xanh'], ['email' => 'chu@vattu.vn', 'phone' => '0912345678']);

        $this->ask('forgot-account', '0912 345 678', 'nong-phat.agrihub.test')->assertStatus(202);

        Notification::assertSentToTimes($owner, AccountReminder::class, 1);
        Notification::assertSentTimes(AccountReminder::class, 1);
    }

    public function test_the_account_reminder_for_an_unknown_account_looks_the_same_and_sends_nothing(): void
    {
        $known = $this->ask('forgot-account', 'khong-co@a.vn')->assertStatus(202);
        $this->makeTenant([], ['email' => 'co@a.vn']);
        $other = $this->ask('forgot-account', 'co@a.vn')->assertStatus(202);

        $this->assertSame($known->getContent(), $other->getContent());
        Notification::assertSentTimes(AccountReminder::class, 1);
    }

    public function test_emails_are_written_in_vietnamese_with_a_working_button(): void
    {
        [, $owner] = $this->makeTenant([], ['name' => 'Nguyễn Văn A', 'email' => 'chu@a.vn']);
        $html = (string) (new ResetPasswordLink('https://app.agrihub.test/reset-password?token=abc', 30))->toMail($owner)->render();

        $this->assertStringContainsString('Xin chào Nguyễn Văn A', $html);
        $this->assertStringContainsString('Đặt lại mật khẩu', $html);
        $this->assertStringContainsString('30 phút', $html);
        $this->assertStringContainsString('https://app.agrihub.test/reset-password?token=abc', $html);
        $this->assertStringContainsString('không bấm được', $html); // phần chân thư đã dịch
        $this->assertStringNotContainsString('Regards', $html);
    }

    // --- đặt lại mật khẩu ----------------------------------------------------------------------------

    public function test_a_valid_link_sets_the_new_password_and_signs_everything_out(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn', 'password' => self::OLD]);
        $this->platform(fn () => $user->forceFill(['must_change_password' => true, 'failed_login_attempts' => 3, 'locked_until' => now()->addMinutes(10)])->save());
        DB::table('sessions')->insert(['id' => 'phien-cu', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->ask('forgot-password', 'chu@a.vn');

        $this->resetWith($user)->assertOk()->assertJsonPath('message', 'Đã đặt lại mật khẩu. Hãy đăng nhập bằng mật khẩu mới.');

        $fresh = $this->platform(fn () => $user->fresh());
        $this->assertTrue(password_verify(self::NEW, $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Notification::assertSentTo($user, PasswordWasReset::class);

        // Mật khẩu mới đăng nhập được, mật khẩu cũ thì không.
        $this->freshBrowser()->spa()->postJson('/api/v1/auth/login', ['login' => 'chu@a.vn', 'password' => self::NEW])->assertOk();
        $this->freshBrowser()->spa()->postJson('/api/v1/auth/login', ['login' => 'chu@a.vn', 'password' => self::OLD])->assertUnauthorized();
    }

    public function test_a_link_works_once_only(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn', 'password' => self::OLD]);
        $this->ask('forgot-password', 'chu@a.vn');
        $link = $this->lastLink($user);
        $body = ['email' => $link['email'], 'token' => $link['token'], 'password' => self::NEW, 'password_confirmation' => self::NEW];

        $this->reset($body)->assertOk();
        $this->reset(['password' => 'Mat-khau-khac-2028-y', 'password_confirmation' => 'Mat-khau-khac-2028-y'] + $body)->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_the_link_expires(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn']);
        $this->ask('forgot-password', 'chu@a.vn');
        $link = $this->lastLink($user);

        $this->travel(31)->minutes();

        $this->reset(['email' => $link['email'], 'token' => $link['token'], 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_every_kind_of_bad_link_gets_the_same_answer(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn']);
        [, $other] = $this->makeTenant([], ['email' => 'khac@b.vn']);
        [, $disabled] = $this->makeTenant([], ['email' => 'khoa@c.vn']);
        $this->ask('forgot-password', 'chu@a.vn');
        $this->ask('forgot-password', 'khoa@c.vn');
        $this->platform(fn () => $disabled->forceFill(['status' => 'disabled'])->save());
        $good = $this->lastLink($user);
        $disabledLink = $this->lastLink($disabled);
        $password = ['password' => self::NEW, 'password_confirmation' => self::NEW];

        $answers = [
            'sai mã' => $this->reset(['email' => 'chu@a.vn', 'token' => str_repeat('x', 64)] + $password),
            'email không tồn tại' => $this->reset(['email' => 'khong-co@a.vn', 'token' => $good['token']] + $password),
            'mã của người khác' => $this->reset(['email' => $other->email, 'token' => $good['token']] + $password),
            'tài khoản bị khóa' => $this->reset(['email' => 'khoa@c.vn', 'token' => $disabledLink['token']] + $password),
        ];

        foreach ($answers as $name => $response) {
            $response->assertStatus(422);
            $this->assertSame('invalid_reset_token', $response->json('code'), $name);
            $this->assertSame('Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Hãy yêu cầu liên kết mới.', $response->json('detail'), $name);
        }

        // Sau toàn bộ các lần thử sai, mã đúng của chính chủ vẫn dùng được.
        $this->reset(['email' => 'chu@a.vn', 'token' => $good['token']] + $password)->assertOk();
    }

    public function test_the_new_password_must_meet_the_policy_and_a_rejected_try_does_not_burn_the_link(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'nguyenvana@a.vn', 'password' => self::OLD]);
        $this->ask('forgot-password', 'nguyenvana@a.vn');
        $link = $this->lastLink($user);
        $base = ['email' => $link['email'], 'token' => $link['token']];

        $this->reset($base + ['password' => 'ngan1', 'password_confirmation' => 'ngan1'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset($base + ['password' => 'khongcoso-chi-chu', 'password_confirmation' => 'khongcoso-chi-chu'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset($base + ['password' => 'NguyenVanA-2027-x', 'password_confirmation' => 'NguyenVanA-2027-x'])->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Mật khẩu không được chứa tên đăng nhập (email).');
        $this->reset($base + ['password' => self::NEW, 'password_confirmation' => self::NEW.'khac'])->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Xác nhận mật khẩu không khớp.');

        $this->reset($base + ['password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk(); // mã chưa bị đốt
    }

    public function test_resets_are_throttled(): void
    {
        foreach (range(1, 10) as $_) {
            $this->reset(['email' => 'a@a.vn', 'token' => 'x', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(422);
        }

        $this->reset(['email' => 'a@a.vn', 'token' => 'x', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(429);
        RateLimiter::clear('reset-password');
    }

    public function test_recovery_is_audited_without_ever_recording_a_token_or_password(): void
    {
        [, $user] = $this->makeTenant([], ['email' => 'chu@a.vn', 'password' => self::OLD]);
        $this->ask('forgot-password', 'chu@a.vn');
        $link = $this->lastLink($user);
        $this->reset(['email' => 'chu@a.vn', 'token' => 'sai', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(422);
        $this->resetWith($user)->assertOk();

        $requested = $this->lastAudit(['module' => 'auth', 'action' => 'password_reset_requested']);
        $this->assertSame('email', $requested->metadata['via']);
        $this->assertSame($user->tenant_id, $requested->tenant_id);
        $this->assertNotNull($this->lastAudit(['module' => 'auth', 'action' => 'password_reset_failed']));
        $completed = $this->lastAudit(['module' => 'auth', 'action' => 'password_reset_completed']);
        $this->assertSame($user->uuid, $completed->actor_uuid);

        $everything = json_encode(DB::table('audit_logs')->get()->all());
        foreach ([$link['token'], self::NEW, 'sai'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_the_super_admin_can_recover_a_password_too(): void
    {
        $this->enableSubdomains();
        $admin = $this->makePlatformAdmin(['email' => 'root@agrihub.test', 'password' => self::OLD]);
        $this->ask('forgot-password', 'root@agrihub.test', 'admin.agrihub.test');

        $this->resetWith($admin)->assertOk();

        $this->freshBrowser()->spa()->postJson('http://admin.agrihub.test/api/v1/auth/login', ['login' => 'root@agrihub.test', 'password' => self::NEW])->assertOk();
    }
}
