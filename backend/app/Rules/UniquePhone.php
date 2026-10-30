<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Auth\Phone;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Số điện thoại là định danh đăng nhập nên duy nhất TOÀN HỆ THỐNG (giống email), kể cả tài khoản đã xóa mềm.
 * Rule `unique` thông thường bị RLS che các Tenant khác nên rule này kiểm tra ở chế độ nền tảng.
 * Thông báo cố ý chung chung để không tiết lộ số thuộc Tenant nào.
 */
class UniquePhone implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $phone = Phone::normalize(is_string($value) ? $value : null);

        if ($phone === null) {
            return; // định dạng sai đã được VietnamesePhone báo
        }

        $taken = app(TenantContext::class)->runAsPlatform(fn () => User::withTrashed()
            ->where('phone', $phone)
            ->when($this->ignoreUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists());

        if ($taken) {
            $fail('Số điện thoại này không khả dụng.');
        }
    }
}
