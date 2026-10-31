<?php

namespace App\Policies;

use App\Models\User;

/**
 * Nhà cung cấp dùng chung toàn doanh nghiệp nên không có "chi nhánh của nhà cung cấp": ai xem được thì xem được tất cả (để chọn khi nhập hàng),
 * ai tạo được thì thêm được; sửa và xóa là thao tác cấp doanh nghiệp vì thay đổi ảnh hưởng mọi chi nhánh (công nợ, điều khoản thanh toán).
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('purchase.supplier.view', 'branch');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('purchase.supplier.create', 'branch');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('purchase.supplier.update', 'tenant');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('purchase.supplier.delete', 'tenant');
    }
}
