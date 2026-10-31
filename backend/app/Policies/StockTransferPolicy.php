<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

/**
 * Phiếu chuyển kho đụng tới HAI chi nhánh (kho gửi và kho nhận). Dùng chung quyền nhập/xuất kho (`inventory.movement`), phạm vi `tenant` = mọi chi nhánh,
 * `branch` = các chi nhánh được gán:
 * - Xem: thấy phiếu nếu có quyền xem ở chi nhánh gửi HOẶC chi nhánh nhận (mỗi chi nhánh phải thấy hàng đi/đến của mình).
 * - Chuyển: cần quyền `create` ở CẢ HAI chi nhánh (vừa xuất khỏi kho gửi vừa nhập vào kho nhận). Nhân viên kho chỉ gán một chi nhánh nên chỉ chuyển giữa các kho
 *   trong chi nhánh của mình; chuyển giữa chi nhánh cần người có phạm vi toàn doanh nghiệp (chủ, quản trị) hoặc được gán cả hai chi nhánh.
 */
class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('inventory.movement.view', 'branch');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->inScope($user, 'inventory.movement.view', $transfer->from_branch_id) || $this->inScope($user, 'inventory.movement.view', $transfer->to_branch_id);
    }

    public function create(User $user, int $fromBranchId, int $toBranchId): bool
    {
        return $this->inScope($user, 'inventory.movement.create', $fromBranchId) && $this->inScope($user, 'inventory.movement.create', $toBranchId);
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
