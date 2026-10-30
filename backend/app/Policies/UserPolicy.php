<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tenant.user.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->inScope($user, 'tenant.user.view', $target);
    }

    /** Tạo người dùng là thao tác cấp doanh nghiệp. */
    public function create(User $user): bool
    {
        return $user->hasPermission('tenant.user.create', 'tenant');
    }

    /** Sửa hồ sơ, đổi trạng thái, gán chi nhánh/vai trò, reset mật khẩu. (Chống leo thang quyền do UserService đảm nhiệm.) */
    public function update(User $user, User $target): bool
    {
        return ! $target->is_platform_admin && $this->inScope($user, 'tenant.user.update', $target);
    }

    public function delete(User $user, User $target): bool
    {
        return ! $target->is_platform_admin && $this->inScope($user, 'tenant.user.delete', $target);
    }

    public function assignRoles(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }

    private function inScope(User $user, string $permission, User $target): bool
    {
        $tenantId = $user->effectiveTenantId();

        if ($tenantId === null || $tenantId !== $target->tenant_id) {
            return false;
        }

        return match ($user->permissionScope($permission)) {
            'tenant' => true,
            'branch' => array_intersect($user->branchIds(), $target->branchIds()) !== [],
            'own' => $user->is($target),
            default => false,
        };
    }
}
