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
 * Mỗi doanh nghiệp có tài khoản riêng: cả email lẫn số điện thoại chỉ duy nhất TRONG một doanh nghiệp, nên phải tra theo địa chỉ
 * đang truy cập (subdomain):
 * - Ở địa chỉ của một doanh nghiệp: chỉ tài khoản của doanh nghiệp đó. Ở cổng quản trị: chỉ tài khoản quản trị nền tảng.
 * - Email không có ở đúng địa chỉ nhưng chỉ MỘT tài khoản trong cả hệ thống dùng nó: vẫn trả về tài khoản đó để đăng nhập báo
 *   "sai địa chỉ" kèm đường dẫn đúng (chỉ khi mật khẩu đã đúng). Số điện thoại thì không: cố ý không chỉ đường.
 * - Chưa bật subdomain (không biết doanh nghiệp nào): chỉ nhận khi CHỈ MỘT tài khoản dùng email/số đó; trùng thì coi như không tìm thấy
 *   (UniqueEmail không cho trùng email trong chế độ này nên chỉ số điện thoại mới gặp trường hợp mơ hồ).
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

            $inScope = match ($scope->kind) {
                HostTenant::TENANT => (clone $query)->where('tenant_id', $scope->tenantId)->first(),
                HostTenant::PLATFORM => (clone $query)->whereNull('tenant_id')->first(),
                default => null,
            };

            if ($inScope !== null) {
                return $inScope;
            }

            if ($identifier->type === 'phone' && in_array($scope->kind, [HostTenant::TENANT, HostTenant::PLATFORM], true)) {
                return null;
            }

            $matches = $query->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        });
    }
}
