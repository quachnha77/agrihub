<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Sản phẩm thuộc MỘT chi nhánh nên phạm vi quyền `catalog.product.*` hiểu là: `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán.
 * Phạm vi `own` không có nghĩa với sản phẩm nên không cho phép gì.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('catalog.product.view', 'branch');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->inScope($user, 'catalog.product.view', $product->branch_id);
    }

    /** Tạo sản phẩm vào chi nhánh $branchId (chi nhánh đích phải nằm trong phạm vi của người tạo). */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'catalog.product.create', $branchId);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->inScope($user, 'catalog.product.update', $product->branch_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->inScope($user, 'catalog.product.delete', $product->branch_id);
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
