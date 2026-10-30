<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Số lần sai liên tiếp trước khi khóa tài khoản tạm thời, và thời gian khóa (phút). */
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return Problem::response(400, 'stateful_request_required', 'Yêu cầu đăng nhập phải đến từ ứng dụng web được phép.');
        }

        $email = Str::lower($request->validated('email'));
        $password = $request->validated('password');

        // Tìm user trước khi biết Tenant => chạy ở chế độ nền tảng (RLS).
        $user = $this->context->runAsPlatform(fn () => User::where('email', $email)->first());

        // Phản hồi giống hệt nhau cho "khóa" và "bị giới hạn tốc độ": không lộ việc tài khoản có tồn tại.
        if ($user?->isLocked()) {
            $this->loginFailed($user, $email, 'account_locked');

            return $this->tooManyAttempts();
        }

        // Luôn băm một lần dù user không tồn tại, để thời gian phản hồi không lộ email.
        $passwordOk = Hash::check($password, $user->password ?? Hash::make(Str::random(32)));

        if ($user === null || ! $passwordOk) {
            if ($user !== null) {
                $this->registerFailure($user);
            }
            $this->loginFailed($user, $email, 'invalid_credentials');

            return Problem::response(401, 'invalid_credentials', 'Email hoặc mật khẩu không đúng.');
        }

        if (! $user->isActive()) {
            $this->loginFailed($user, $email, 'account_disabled');

            return Problem::response(403, 'account_disabled', 'Tài khoản đã bị vô hiệu hóa.');
        }

        $tenant = $this->context->runAsPlatform(fn () => $user->tenant);
        if ($tenant !== null && ! $tenant->isActive()) {
            $this->loginFailed($user, $email, 'tenant_suspended');

            return Problem::response(403, 'tenant_suspended', 'Doanh nghiệp đang bị tạm khóa. Vui lòng liên hệ AgriHub.');
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

    /** Đăng nhập thất bại: ghi cả khi email không tồn tại (không có chủ thể), không bao giờ ghi mật khẩu. */
    private function loginFailed(?User $user, string $email, string $reason): void
    {
        $this->audit->record(
            'auth', 'login_failed',
            subject: $user,
            result: AuditLogger::FAILURE,
            reason: $reason,
            metadata: $user === null ? ['email' => $email] : [],
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
