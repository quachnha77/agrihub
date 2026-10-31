<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\Warehouse;
use App\Support\Audit\AuditSuppression;

/**
 * Chi nhánh nào cũng có sẵn một kho ("Kho chính") để nhập hàng ngay; có thể thêm kho khác hoặc đổi tên.
 * Không ghi audit riêng cho kho này: việc tạo chi nhánh đã có sự kiện của nó (giống 7 vai trò hệ thống của doanh nghiệp mới).
 */
class BranchObserver
{
    public function created(Branch $branch): void
    {
        $warehouse = new Warehouse(['code' => 'KHO-01', 'name' => 'Kho chính']);
        $warehouse->tenant_id = $branch->tenant_id; // đúng cả khi tạo chi nhánh ở chế độ nền tảng (onboarding doanh nghiệp)
        $warehouse->branch_id = $branch->id;
        AuditSuppression::run(fn () => $warehouse->save());
    }
}
