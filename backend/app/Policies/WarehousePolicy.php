<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

/** Kho thuộc MỘT chi nhánh nên phạm vi quyền `inventory.warehouse.*` hiểu là: `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán. `own` không cho gì. */
class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.warehouse.view', 'branch');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->inScope($user, 'inventory.warehouse.view', $warehouse->branch_id);
    }

    /** Tạo kho cho chi nhánh $branchId (phải nằm trong phạm vi của người tạo). */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'inventory.warehouse.create', $branchId);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->inScope($user, 'inventory.warehouse.update', $warehouse->branch_id);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $this->inScope($user, 'inventory.warehouse.delete', $warehouse->branch_id);
    }

    private function inScope(User $user, string $permission, int $branchId): bool
    {
        return match ($user->permissionScope($permission)) {
            'tenant' => true,
            'branch' => in_array($branchId, $user->branchIds(), true),
            default => false,
        };
    }
}
