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

    /** Gán/gỡ vai trò (việc kiểm tra leo thang quyền nằm ở UserController). */
    public function assignRoles(User $user, User $target): bool
    {
        return ! $target->is_platform_admin && $this->inScope($user, 'tenant.user.update', $target);
    }

    private function inScope(User $user, string $permission, User $target): bool
    {
        if ($user->tenant_id === null || $user->tenant_id !== $target->tenant_id) {
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
