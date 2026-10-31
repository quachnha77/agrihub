<?php

namespace App\Services\Auth;

use App\Http\ApiProblemException;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\AccountReminder;
use App\Notifications\Auth\PasswordWasReset;
use App\Notifications\Auth\ResetPasswordLink;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditSuppression;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginScope;
use App\Support\Auth\Phone;
use App\Support\Tenancy\HostTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Quên mật khẩu / quên tài khoản. Mọi thứ chỉ đi qua EMAIL của chính tài khoản (chưa có nhà cung cấp SMS):
 * nhập số điện thoại thì hệ thống tìm tài khoản rồi gửi tới email đã đăng ký, không bao giờ hiển thị email đó.
 *
 * Nguyên tắc:
 * - Người gọi không được biết tài khoản có tồn tại hay không: các hàm `send*` chạy trong hàng đợi (job) sau khi đã trả lời chung,
 *   và không có giá trị trả về.
 * - Liên kết dựng từ cấu hình + dữ liệu của tài khoản, không từ header Host (chống giả mạo Host để chuyển hướng liên kết).
 * - Mã đặt lại do Laravel quản lý (băm trong password_reset_tokens, dùng một lần, hết hạn, giãn cách giữa các lần yêu cầu).
 * - Đặt lại thành công: đăng xuất mọi thiết bị, mở khóa đăng nhập sai, hết yêu cầu đổi mật khẩu tạm.
 */
class PasswordRecovery
{
    /** Số email khôi phục tối đa gửi cho MỘT tài khoản mỗi giờ (chặn việc dùng hệ thống để gửi thư rác cho một người). */
    private const MAX_MAILS_PER_HOUR = 3;

    public function __construct(
        private TenantContext $context,
        private AccountLocator $accounts,
        private HostTenant $host,
        private SessionRevoker $sessions,
        private AuditLogger $audit,
    ) {}

    /** Gửi email chứa liên kết đặt lại mật khẩu (nếu tìm thấy tài khoản hợp lệ). */
    public function sendResetLink(LoginIdentifier $identifier, LoginScope $scope, ?string $ip = null): void
    {
        $user = $this->findActiveUser('password_reset_requested', $identifier, $scope, $ip);

        if ($user === null) {
            return;
        }

        $broker = Password::broker();

        // Giãn cách giữa hai lần yêu cầu cho cùng tài khoản (cấu hình auth.passwords.users.throttle): bấm hai lần chỉ gửi một thư,
        // và lần bị giãn cách không bị tính vào hạn mức thư mỗi giờ.
        if ($this->context->runAsPlatform(fn () => $broker->getRepository()->recentlyCreatedToken($user))) {
            $this->record('password_reset_requested', $user, $identifier, $ip, 'throttled');

            return;
        }

        $url = $this->host->loginUrlFor($user);

        if ($url === null || ! $this->mayEmail('password_reset_requested', $user, $identifier, $ip)) {
            return;
        }

        $token = $this->context->runAsPlatform(fn () => $broker->createToken($user));

        $user->notifyNow(new ResetPasswordLink(
            $url.'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]),
            (int) config('auth.passwords.users.expire'),
        ));

        $this->record('password_reset_requested', $user, $identifier, $ip);
    }

    /** Gửi email "nhắc tài khoản": địa chỉ đăng nhập của doanh nghiệp, email đăng nhập, số điện thoại (che bớt). */
    public function sendAccountReminder(LoginIdentifier $identifier, LoginScope $scope, ?string $ip = null): void
    {
        $user = $this->findActiveUser('account_reminder_requested', $identifier, $scope, $ip);

        if ($user === null) {
            return;
        }

        $url = $this->host->loginUrlFor($user);

        if ($url === null || ! $this->mayEmail('account_reminder_requested', $user, $identifier, $ip)) {
            return;
        }

        $business = $user->is_platform_admin
            ? null
            : $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($user->tenant_id)->value('name'));

        $user->notifyNow(new AccountReminder($url, is_string($business) ? $business : null, $user->email, $user->phone ? Phone::mask($user->phone) : null));

        $this->record('account_reminder_requested', $user, $identifier, $ip);
    }

    /**
     * Đặt mật khẩu mới bằng mã trong email.
     *
     * Tài khoản tìm theo email VÀ địa chỉ đang truy cập (liên kết trong thư trỏ đúng subdomain của doanh nghiệp), vì cùng một email có thể
     * có ở nhiều doanh nghiệp. Mã gắn với từng tài khoản (xem User::getEmailForPasswordReset) nên mã của tài khoản này không mở được tài khoản khác.
     *
     * @throws ApiProblemException 422 invalid_reset_token khi email/mã sai, đã dùng hoặc đã hết hạn (không phân biệt, không lộ gì)
     */
    public function reset(string $email, string $token, string $password, LoginScope $scope): void
    {
        $identifier = LoginIdentifier::parse($email);
        $user = $identifier === null ? null : $this->accounts->find($identifier, $scope);

        $repository = Password::broker()->getRepository();

        // Phần còn lại của Password::reset() nhưng không tra lại tài khoản theo email (sẽ mơ hồ khi email trùng giữa các doanh nghiệp).
        $reset = $user !== null && $user->isActive() && $this->context->runAsPlatform(function () use ($user, $token, $password, $repository) {
            if (! $repository->exists($user, $token)) {
                return false;
            }

            $this->apply($user, $password);
            $repository->delete($user);

            return true;
        });

        if (! $reset) {
            $this->audit->record('auth', 'password_reset_failed', subject: $user, result: AuditLogger::FAILURE, reason: 'invalid_token', actor: null);

            throw new ApiProblemException(422, 'invalid_reset_token', 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Hãy yêu cầu liên kết mới.');
        }

        // Báo cho chủ tài khoản; lỗi gửi thư không được làm hỏng việc đã đổi mật khẩu.
        try {
            $user->notifyNow(new PasswordWasReset((string) $this->host->loginUrlFor($user)));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function apply(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            AuditSuppression::run(fn () => $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save());

            $revoked = $this->sessions->forUser($user->id);

            $this->audit->record('auth', 'password_reset_completed', subject: $user, actor: $user, metadata: ['sessions_revoked' => $revoked]);
        });
    }

    /**
     * Tài khoản tồn tại và đang hoạt động. Mọi trường hợp bị loại đều ghi audit (kể cả không tồn tại) nhưng KHÔNG phản hồi gì ra ngoài.
     */
    private function findActiveUser(string $action, LoginIdentifier $identifier, LoginScope $scope, ?string $ip): ?User
    {
        $user = $this->accounts->find($identifier, $scope);

        if ($user === null) {
            $this->record($action, null, $identifier, $ip, 'unknown_account');

            return null;
        }

        if (! $user->isActive()) {
            $this->record($action, $user, $identifier, $ip, 'account_disabled');

            return null;
        }

        return $user;
    }

    /** Còn trong hạn mức thư khôi phục mỗi giờ của tài khoản này. Gọi ngay trước khi gửi: chỉ thư thật sự gửi mới bị tính. */
    private function mayEmail(string $action, User $user, LoginIdentifier $identifier, ?string $ip): bool
    {
        if (RateLimiter::attempt("recovery-mail:{$user->id}", self::MAX_MAILS_PER_HOUR, fn () => true, 3600)) {
            return true;
        }

        $this->record($action, $user, $identifier, $ip, 'rate_limited');

        return false;
    }

    private function record(string $action, ?User $user, LoginIdentifier $identifier, ?string $ip, ?string $failure = null): void
    {
        $metadata = ['via' => $identifier->type, 'requested_from' => $ip];

        if ($user === null) {
            $metadata[$identifier->type] = $identifier->value; // như login_failed: để thấy ai đang dò tài khoản
        }

        $this->audit->record(
            'auth', $action,
            subject: $user,
            result: $failure === null ? AuditLogger::SUCCESS : AuditLogger::FAILURE,
            reason: $failure,
            metadata: $metadata,
            actor: null,
        );
    }
}
