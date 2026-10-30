<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Auth\Phone;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Số điện thoại là định danh đăng nhập nên duy nhất TRONG một doanh nghiệp (mỗi doanh nghiệp có subdomain riêng nên hai doanh nghiệp
 * dùng chung một số vẫn đăng nhập đúng tài khoản của mình). Tài khoản quản trị nền tảng (không thuộc doanh nghiệp nào) tạo thành một nhóm riêng.
 * Tính cả tài khoản đã xóa mềm. Rule `unique` thông thường bị RLS che nên rule này kiểm tra ở chế độ nền tảng.
 * Chỉ so với tài khoản CÙNG phạm vi nên thông báo không tiết lộ gì về doanh nghiệp khác.
 */
class UniquePhone implements ValidationRule
{
    /**
     * @param  int|null  $tenantId  doanh nghiệp của tài khoản đang tạo/sửa; null = nhóm quản trị nền tảng
     * @param  int|null  $ignoreUserId  bỏ qua chính tài khoản đang sửa
     */
    public function __construct(private ?int $tenantId, private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $phone = Phone::normalize(is_string($value) ? $value : null);

        if ($phone === null) {
            return; // định dạng sai đã được VietnamesePhone báo
        }

        $taken = app(TenantContext::class)->runAsPlatform(fn () => User::withTrashed()
            ->where('phone', $phone)
            ->when($this->tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->when($this->ignoreUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists());

        if ($taken) {
            $fail('Số điện thoại này đã được dùng cho một tài khoản khác.');
        }
    }
}
