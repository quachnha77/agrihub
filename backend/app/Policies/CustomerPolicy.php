<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Khách hàng dùng chung toàn doanh nghiệp nhưng mỗi khách có MỘT chi nhánh phụ trách, nên phạm vi quyền `crm.customer.*` hiểu là:
 * `tenant` = mọi khách, `branch` = khách thuộc các chi nhánh được gán. Phạm vi `own` (nhân viên giao hàng chỉ thấy khách được giao)
 * chưa có nghĩa vì chưa có phân công giao hàng nên chưa cho phép gì.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('crm.customer.view', 'branch');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->inScope($user, 'crm.customer.view', $customer->branch_id);
    }

    /** Tạo khách do chi nhánh $branchId phụ trách (cũng dùng khi chuyển khách sang chi nhánh khác). */
    public function create(User $user, int $branchId): bool
    {
        return $this->inScope($user, 'crm.customer.create', $branchId);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->inScope($user, 'crm.customer.update', $customer->branch_id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->inScope($user, 'crm.customer.delete', $customer->branch_id);
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
