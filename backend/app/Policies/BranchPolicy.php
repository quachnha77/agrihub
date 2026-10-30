<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tenant.branch.view', 'branch'); // 'own' không có nghĩa với chi nhánh
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->inScope($user, 'tenant.branch.view', $branch);
    }

    /** Tạo chi nhánh là thao tác cấp doanh nghiệp. */
    public function create(User $user): bool
    {
        return $user->hasPermission('tenant.branch.create', 'tenant');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->inScope($user, 'tenant.branch.update', $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->inScope($user, 'tenant.branch.delete', $branch);
    }

    private function inScope(User $user, string $permission, Branch $branch): bool
    {
        return match ($user->permissionScope($permission)) {
            'tenant' => true,
            'branch' => in_array($branch->id, $user->branchIds(), true),
            default => false, // 'own' không có ý nghĩa với chi nhánh
        };
    }
}
