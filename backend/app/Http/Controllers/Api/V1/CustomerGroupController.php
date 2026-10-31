<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Model;

/** Nhóm khách hàng (dùng chung toàn doanh nghiệp, chỉ để phân loại và lọc). */
class CustomerGroupController extends CatalogListController
{
    protected function model(): string
    {
        return CustomerGroup::class;
    }

    protected function nameMax(): int
    {
        return 100;
    }

    protected function label(): string
    {
        return 'Nhóm khách hàng';
    }

    /** Chỉ tính khách còn hoạt động: khách đã xóa mềm chỉ mất liên kết nhóm khi nhóm bị xóa. */
    protected function inUse(Model $item): bool
    {
        return Customer::where('group_id', $item->id)->exists();
    }
}
