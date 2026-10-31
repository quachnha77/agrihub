<?php

namespace App\Policies;

use App\Models\Stocktake;
use App\Models\User;

/**
 * Kiểm kê thuộc chi nhánh của kho: phạm vi quyền hiểu là `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán. Quyền dùng chung với điều chỉnh kho
 * (`inventory.adjustment`, nhãn "Kiểm kê & điều chỉnh kho"): xem = `view`; bắt đầu, ghi số đếm, hoàn tất, hủy = `create`. Hành động duyệt (`approve`) KHÔNG dùng:
 * chủ sản phẩm chọn kiểm kê không cần duyệt (2026-10-31). Giá vốn/giá trị chênh lệch theo `StockDocumentPolicy::seeCosts`.
 */
class StocktakePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.adjustment.view', 'branch');
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $this->inScope($user, 'inventory.adjustment.view', $stocktake->branch_id);
    }

    /** Bắt đầu một đợt kiểm kê cho kho của chi nhánh $branchId. */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'inventory.adjustment.create', $branchId);
    }

    /** Ghi số đếm, hoàn tất hoặc hủy một đợt. */
    public function update(User $user, Stocktake $stocktake): bool
    {
        return $this->inScope($user, 'inventory.adjustment.create', $stocktake->branch_id);
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
