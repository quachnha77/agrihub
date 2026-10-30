<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Billing\SubscriptionService;
use App\Support\Audit\AuditSuppression;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\PasswordPolicy;
use App\Support\Tenancy\HostTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Số lần sai liên tiếp trước khi khóa tài khoản tạm thời, và thời gian khóa (phút). */
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(private TenantContext $context, private AuditLogger $audit, private SessionRevoker $sessions, private SubscriptionService $billing, private HostTenant $host) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return Problem::response(400, 'stateful_request_required', 'Yêu cầu đăng nhập phải đến từ ứng dụng web được phép.');
        }

        $identifier = $request->identifier(); // email hoặc số điện thoại đã chuẩn hóa
        $password = $request->validated('password');

        // Tìm user trước khi biết Tenant => chạy ở chế độ nền tảng (RLS). Cột tra cứu do chính LoginIdentifier quyết định.
        $user = $this->context->runAsPlatform(fn () => User::where($identifier->type, $identifier->value)->first());

        // Phản hồi giống hệt nhau cho "khóa" và "bị giới hạn tốc độ": không lộ việc tài khoản có tồn tại.
        if ($user?->isLocked()) {
            $this->loginFailed($user, $identifier, 'account_locked');

            return $this->tooManyAttempts();
        }

        // Luôn băm một lần dù user không tồn tại, để thời gian phản hồi không lộ email.
        $passwordOk = Hash::check($password, $user->password ?? Hash::make(Str::random(32)));

        if ($user === null || ! $passwordOk) {
            if ($user !== null) {
                $this->registerFailure($user);
            }
            $this->loginFailed($user, $identifier, 'invalid_credentials');

            return Problem::response(401, 'invalid_credentials', 'Tài khoản hoặc mật khẩu không đúng.');
        }

        // Mỗi doanh nghiệp một subdomain: chỉ nói "sai địa chỉ" khi mật khẩu đã ĐÚNG (người lạ không biết được tài khoản thuộc doanh nghiệp nào).
        if ($this->wrongHost($user)) {
            $this->loginFailed($user, $identifier, 'wrong_host');

            return Problem::response(403, 'wrong_host', 'Tài khoản này không đăng nhập tại địa chỉ này. Hãy dùng địa chỉ riêng của doanh nghiệp bạn.', null, [
                'login_url' => $this->correctUrl($user),
            ]);
        }

        if (! $user->isActive()) {
            $this->loginFailed($user, $identifier, 'account_disabled');

            return Problem::response(403, 'account_disabled', 'Tài khoản đã bị vô hiệu hóa.');
        }

        $tenant = $this->context->runAsPlatform(fn () => $user->tenant);
        if ($tenant !== null && ! $tenant->isActive()) {
            $this->loginFailed($user, $identifier, 'tenant_suspended');

            return Problem::response(403, 'tenant_suspended', 'Doanh nghiệp đang bị tạm khóa. Vui lòng liên hệ AgriHub.');
        }

        if ($tenant !== null && $this->billing->current($tenant->id)?->isBlocked()) {
            $this->loginFailed($user, $identifier, 'subscription_expired');

            return Problem::response(402, 'subscription_expired', 'Gói dịch vụ đã hết hạn. Vui lòng liên hệ AgriHub để gia hạn.');
        }

        $this->context->runAsPlatform(function () use ($user) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
            ])->save();
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->audit->record('auth', 'login', subject: $user, actor: $user);

        return $this->userResponse($user);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        $this->audit->record('auth', 'logout', subject: $user, actor: $user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return $this->userResponse($request->user());
    }

    /**
     * Tự đổi mật khẩu. Bắt buộc với tài khoản đang dùng mật khẩu tạm. Thành công thì đăng xuất mọi thiết bị khác
     * và đổi mã phiên hiện tại (phiên cũ bị hủy).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => array_merge(['required', 'confirmed', 'different:current_password'], PasswordPolicy::rules($user->email)),
        ], [
            'password.confirmed' => 'Xác nhận mật khẩu không khớp.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            $this->audit->record('auth', 'password_change_failed', subject: $user, actor: $user, result: AuditLogger::FAILURE, reason: 'invalid_current_password');

            throw ValidationException::withMessages(['current_password' => ['Mật khẩu hiện tại không đúng.']]);
        }

        $forced = (bool) $user->must_change_password;

        // Platform Admin không có context Tenant nên cập nhật hàng của chính mình ở chế độ nền tảng (RLS).
        $revoked = $this->context->runAsPlatform(fn () => DB::transaction(function () use ($user, $validated, $request) {
            AuditSuppression::run(fn () => $user->forceFill([
                'password' => $validated['password'],
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save());

            return $this->sessions->forUser($user->id, $request->hasSession() ? $request->session()->getId() : null);
        }));

        if ($request->hasSession()) {
            $request->session()->regenerate(destroy: true);
        }

        $this->audit->record('auth', 'password_changed', subject: $user, actor: $user, metadata: [
            'forced_change' => $forced,
            'other_sessions_revoked' => $revoked,
        ]);

        // Nạp lại ở chế độ nền tảng: Platform Admin không có context Tenant nên RLS sẽ che hàng của chính họ.
        return $this->userResponse($this->context->runAsPlatform(fn () => $user->refresh()));
    }

    private function userResponse(User $user): JsonResponse
    {
        // Nạp quan hệ trong đúng context của user (RLS chỉ cho phép dữ liệu Tenant của họ).
        $load = fn () => $user->load(['tenant', 'branches', 'roles']);

        $user->is_platform_admin
            ? $this->context->runAsPlatform($load)
            : $this->context->runForTenant((int) $user->tenant_id, $load);

        $user->flushPermissionCache();

        return (new UserResource($user))->withPermissions()->response();
    }

    private function wrongHost(User $user): bool
    {
        return match ($this->host->kind()) {
            HostTenant::TENANT => $user->is_platform_admin || $user->tenant_id !== $this->host->tenantId(),
            HostTenant::PLATFORM => ! $user->is_platform_admin,
            default => false,
        };
    }

    private function correctUrl(User $user): ?string
    {
        if ($user->is_platform_admin) {
            return $this->host->platformUrl();
        }

        $slug = $this->context->runAsPlatform(fn () => Tenant::query()->whereKey($user->tenant_id)->value('slug'));

        return is_string($slug) ? $this->host->urlFor($slug) : null;
    }

    /** Đăng nhập thất bại: ghi cả khi email không tồn tại (không có chủ thể), không bao giờ ghi mật khẩu. */
    private function loginFailed(?User $user, LoginIdentifier $identifier, string $reason): void
    {
        $this->audit->record(
            'auth', 'login_failed',
            subject: $user,
            result: AuditLogger::FAILURE,
            reason: $reason,
            metadata: $user === null ? [$identifier->type => $identifier->value] : [],
            actor: null,
        );
    }

    private function registerFailure(User $user): void
    {
        $locked = $this->context->runAsPlatform(function () use ($user) {
            $attempts = $user->failed_login_attempts + 1;

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->save();

                return true;
            }

            $user->forceFill(['failed_login_attempts' => $attempts])->save();

            return false;
        });

        if ($locked) {
            $this->audit->record(
                'auth', 'account_locked',
                subject: $user,
                result: AuditLogger::DENIED,
                reason: 'too_many_failed_attempts',
                metadata: ['locked_minutes' => self::LOCK_MINUTES],
                actor: null,
            );
        }
    }

    private function tooManyAttempts(): JsonResponse
    {
        return Problem::response(429, 'too_many_attempts', 'Quá nhiều lần thử. Vui lòng thử lại sau.', null, [
            'retry_after' => self::LOCK_MINUTES * 60,
        ])->header('Retry-After', (string) (self::LOCK_MINUTES * 60));
    }
}
