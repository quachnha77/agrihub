<?php

namespace App\Policies;

use App\Models\PurchaseReceipt;
use App\Models\User;

/**
 * Phiếu nhập hàng thuộc chi nhánh của kho nhận: phạm vi quyền hiểu là `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán. Dùng quyền `purchase.order` ("Mua hàng"):
 * xem = `view`; lập phiếu = `create`; ghi lần trả tiền (kể cả lần trả đầu tiên khi lập phiếu) = `update`; hủy một lần trả = `cancel`. Hành động duyệt (`approve`) KHÔNG dùng:
 * chủ sản phẩm chọn không cần duyệt (2026-11-01). Nhà cung cấp dùng chung cả doanh nghiệp nên không giới hạn theo chi nhánh.
 */
class PurchaseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchase.order.view', 'branch');
    }

    public function view(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->inScope($user, 'purchase.order.view', $receipt->branch_id);
    }

    /** Lập phiếu nhập hàng vào kho của chi nhánh $branchId. */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'purchase.order.create', $branchId);
    }

    /** Ghi một lần trả tiền cho phiếu của chi nhánh $branchId. */
    public function pay(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'purchase.order.update', $branchId);
    }

    public function voidPayment(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->inScope($user, 'purchase.order.cancel', $receipt->branch_id);
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
