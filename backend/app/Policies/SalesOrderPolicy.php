<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;

/**
 * Phiếu bán thuộc chi nhánh của kho bán: phạm vi quyền hiểu là `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán, `own` = chỉ phiếu do chính người dùng lập
 * (nhân viên bán hàng có `own` cho sửa/hủy). Dùng quyền `sales.order`: xem = `view`; lập phiếu (kể cả thu đủ tiền ngay lúc bán) = `create`; thu thêm tiền sau đó = `update`;
 * hủy một lần thu = `cancel`. Hành động duyệt (`approve`) KHÔNG dùng: quy trình đơn giản, không duyệt. Khách hàng có phạm vi riêng (CustomerPolicy).
 */
class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sales.order.view', 'branch');
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $this->inScope($user, 'sales.order.view', $order->branch_id, null);
    }

    /** Lập phiếu bán từ kho của chi nhánh $branchId. */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'sales.order.create', $branchId, null);
    }

    /** Thu thêm tiền cho phiếu của chi nhánh $branchId do $createdBy lập. */
    public function collect(User $user, SalesOrder $order): bool
    {
        return $this->inScope($user, 'sales.order.update', $order->branch_id, $order->created_by);
    }

    public function voidPayment(User $user, SalesOrder $order): bool
    {
        return $this->inScope($user, 'sales.order.cancel', $order->branch_id, $order->created_by);
    }

    private function inScope(User $user, string $permission, int $branchId, ?int $createdBy): bool
    {
        return match ($user->permissionScope($permission)) {
            'tenant' => true,
            'branch' => in_array($branchId, $user->branchIds(), true),
            'own' => $createdBy !== null && $createdBy === $user->id && in_array($branchId, $user->branchIds(), true),
            default => false,
        };
    }
}
