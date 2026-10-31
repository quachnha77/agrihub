<?php

namespace App\Policies;

use App\Models\User;

/** Nhóm khách hàng: dùng chung toàn doanh nghiệp. Xem/thêm theo quyền khách hàng; đổi tên và xóa là thao tác cấp doanh nghiệp. */
class CustomerGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('crm.customer.view', 'branch');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('crm.customer.create', 'branch');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('crm.customer.update', 'tenant');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('crm.customer.delete', 'tenant');
    }
}
