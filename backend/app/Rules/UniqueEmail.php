<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Email là định danh đăng nhập và duy nhất TOÀN HỆ THỐNG (mỗi user thuộc đúng 1 Tenant).
 * Rule `unique` thông thường bị RLS che các Tenant khác nên không đủ: rule này kiểm tra ở chế độ nền tảng, kể cả tài khoản đã xóa mềm.
 * Thông báo cố ý chung chung để không tiết lộ email thuộc Tenant nào.
 */
class UniqueEmail implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = mb_strtolower(trim((string) $value));

        $taken = app(TenantContext::class)->runAsPlatform(fn () => User::withTrashed()
            ->where('email', $email)
            ->when($this->ignoreUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists());

        if ($taken) {
            $fail('Email này không khả dụng.');
        }
    }
}
