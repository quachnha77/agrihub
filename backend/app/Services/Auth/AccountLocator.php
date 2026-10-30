<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginScope;
use App\Support\Tenancy\HostTenant;
use App\Support\Tenancy\TenantContext;

/**
 * Tìm tài khoản từ thứ người dùng gõ vào (email hoặc số điện thoại) — dùng chung cho đăng nhập và khôi phục tài khoản.
 *
 * - Email: duy nhất toàn hệ thống, tra thẳng.
 * - Số điện thoại: chỉ duy nhất trong từng doanh nghiệp, nên tra theo địa chỉ đang truy cập (subdomain).
 *   Khi chưa bật subdomain (không biết doanh nghiệp nào) chỉ nhận số điện thoại nếu CHỈ MỘT tài khoản dùng số đó;
 *   trùng thì coi như không tìm thấy (người dùng đăng nhập bằng email thay thế).
 *
 * Tra cứu chạy ở chế độ nền tảng vì phải tìm user trước khi biết Tenant (RLS).
 */
class AccountLocator
{
    public function __construct(private TenantContext $context) {}

    public function find(LoginIdentifier $identifier, LoginScope $scope): ?User
    {
        return $this->context->runAsPlatform(function () use ($identifier, $scope) {
            $query = User::query()->where($identifier->type, $identifier->value);

            if ($identifier->type === 'email') {
                return $query->first();
            }

            if ($scope->kind === HostTenant::TENANT) {
                return $query->where('tenant_id', $scope->tenantId)->first();
            }

            if ($scope->kind === HostTenant::PLATFORM) {
                return $query->whereNull('tenant_id')->first();
            }

            $matches = $query->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        });
    }
}
