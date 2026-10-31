<?php

namespace App\Policies;

use App\Models\StockDocument;
use App\Models\User;

/**
 * Phiếu kho thuộc chi nhánh của kho: phạm vi quyền hiểu là `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán.
 * Nhập kho / xuất kho / tồn đầu kỳ cần `inventory.movement.create`; điều chỉnh (làm tăng/giảm tồn không có chứng từ gốc) cần `inventory.adjustment.create`.
 */
class StockDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.movement.view', 'branch');
    }

    public function view(User $user, StockDocument $document): bool
    {
        return $this->inScope($user, 'inventory.movement.view', $document->branch_id);
    }

    /** Ghi phiếu loại $type vào kho của chi nhánh $branchId. */
    public function create(User $user, int $branchId, string $type): bool
    {
        return $this->inScope($user, $type === StockDocument::ADJUSTMENT ? 'inventory.adjustment.create' : 'inventory.movement.create', $branchId);
    }

    /**
     * Giá vốn là số liệu nhạy cảm (lộ ra là lộ biên lợi nhuận): chỉ người làm kho (có quyền nhập kho, vì họ phải nhập giá) và người xem báo cáo
     * ở phạm vi chi nhánh trở lên (kế toán, quản lý) mới thấy. Nhân viên bán hàng chỉ xem được số lượng.
     */
    public function seeCosts(User $user): bool
    {
        return $user->hasPermission('inventory.movement.create') || $user->hasPermission('report.report.view', 'branch');
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
