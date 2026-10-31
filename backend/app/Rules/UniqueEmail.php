<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Email là định danh đăng nhập. Mỗi doanh nghiệp có tài khoản riêng nên email chỉ cần duy nhất TRONG doanh nghiệp (và trong nhóm quản trị
 * nền tảng): hai doanh nghiệp được dùng chung một email, mỗi nơi đăng nhập ở subdomain của mình.
 *
 * Ngoại lệ: khi chưa bật subdomain (TENANT_BASE_DOMAIN trống) không có địa chỉ nào để phân biệt doanh nghiệp, một email dùng ở hai nơi
 * sẽ không đăng nhập được, nên lúc đó email vẫn duy nhất toàn hệ thống.
 *
 * Tính cả tài khoản đã xóa mềm. Rule `unique` thông thường bị RLS che các doanh nghiệp khác nên rule này kiểm tra ở chế độ nền tảng.
 * Thông báo cố ý chung chung để không tiết lộ email đang dùng ở đâu.
 */
class UniqueEmail implements ValidationRule
{
    /** Doanh nghiệp chưa tồn tại (đang onboarding): chưa có tài khoản nào để trùng trong phạm vi của nó. */
    private const NEW_TENANT = 0;

    /**
     * @param  int|null  $tenantId  doanh nghiệp của tài khoản đang tạo; null = nhóm quản trị nền tảng
     * @param  int|null  $ignoreUserId  bỏ qua chính tài khoản đang sửa
     */
    public function __construct(private ?int $tenantId, private ?int $ignoreUserId = null) {}

    /** Email của chủ một doanh nghiệp MỚI: chỉ phải khác email đã dùng ở mọi nơi khi chưa bật subdomain. */
    public static function forNewTenant(): self
    {
        return new self(self::NEW_TENANT);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::taken((string) $value, $this->tenantId, $this->ignoreUserId)) {
            $fail('Email này không khả dụng.');
        }
    }

    public static function taken(string $email, ?int $tenantId, ?int $ignoreUserId = null): bool
    {
        $email = mb_strtolower(trim($email));
        $perTenant = self::perTenant();

        return app(TenantContext::class)->runAsPlatform(fn () => User::withTrashed()
            ->where('email', $email)
            ->when($perTenant, fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId))
            ->when($ignoreUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists());
    }

    /** Có subdomain riêng cho từng doanh nghiệp thì email mới được phép trùng giữa các doanh nghiệp. */
    private static function perTenant(): bool
    {
        $base = config('tenancy.base_domain');

        return is_string($base) && $base !== '';
    }
}
