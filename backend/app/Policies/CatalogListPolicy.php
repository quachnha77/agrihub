<?php

namespace App\Policies;

use App\Models\User;

/**
 * Đơn vị tính, nhóm hàng, thương hiệu: danh sách dùng chung toàn doanh nghiệp nên dùng quyền `catalog.product.*`:
 * xem và thêm mới cho mọi người làm được với sản phẩm (kể cả quản lý chi nhánh, vì họ cần thêm đơn vị khi nhập hàng),
 * còn sửa/xóa là thao tác cấp doanh nghiệp vì đổi tên một mục ảnh hưởng mọi chi nhánh.
 */
class CatalogListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('catalog.product.view', 'branch');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('catalog.product.create', 'branch');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('catalog.product.update', 'tenant');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('catalog.product.delete', 'tenant');
    }
}
