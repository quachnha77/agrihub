<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chuyển kho (POST /stock/transfers): kho gửi, kho nhận, ghi chú và các dòng hàng. Chỉ kiểm tra HÌNH DẠNG dữ liệu; quy tắc kho (cùng kho, kho bị khóa kiểm kê,
 * sản phẩm bên nhận, đủ tồn, lô...) do StockService kiểm tra và báo theo từng dòng. Sản phẩm là sản phẩm ở KHO GỬI; số lượng theo đơn vị người dùng chọn.
 */
class StockTransferRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.movement.create') ?? false;
    }

    public function fromWarehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('from_warehouse_id'))->firstOrFail();
    }

    public function toWarehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('to_warehouse_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $warehouse = Rule::exists('warehouses', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'from_warehouse_id' => ['required', 'uuid', $warehouse],
            'to_warehouse_id' => ['required', 'uuid', $warehouse],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.unit_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999'],
            'lines.*.batch_id' => ['nullable', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'from_warehouse_id' => 'kho gửi', 'to_warehouse_id' => 'kho nhận', 'note' => 'ghi chú', 'lines' => 'danh sách hàng',
            'lines.*.product_id' => 'sản phẩm', 'lines.*.unit_id' => 'đơn vị', 'lines.*.quantity' => 'số lượng', 'lines.*.batch_id' => 'lô',
        ];
    }
}
