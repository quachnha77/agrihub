<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;

/**
 * Chống leo thang quyền: chỉ được tạo/sửa vai trò có quyền KHÔNG vượt quá quyền của chính mình,
 * Owner bất biến, vai trò hệ thống không xóa được.
 */
class RolePolicy
{
    public function __construct(private RoleService $roles) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tenant.role.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('tenant.role.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('tenant.role.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('tenant.role.update')
            && ! $role->isOwner()
            && Catalog::covers($user->permissionMap(), $this->roles->grants($role));
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('tenant.role.delete')
            && ! $role->is_system
            && Catalog::covers($user->permissionMap(), $this->roles->grants($role));
    }
}
