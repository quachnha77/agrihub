<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use App\Models\SalesPayment;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lập phiếu bán hàng (POST /sales): kho bán, khách (trống = khách lẻ), các dòng hàng (đơn vị và đơn giá theo cách người bán nhập), số tiền bớt và số tiền thu ngay.
 * Chỉ kiểm tra HÌNH DẠNG; quy tắc nghiệp vụ (kho còn hoạt động, đủ tồn, hạn mức công nợ...) do SalesService / StockService kiểm tra và báo theo ô.
 * Không đưa lô vào đây: máy chủ tự chọn lô gần hết hạn trước.
 */
class SalesOrderRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can('sales.order.create') ?? false;
    }

    public function warehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('warehouse_id'))->firstOrFail();
    }

    public function customer(): ?Customer
    {
        $id = $this->validated('customer_id');

        return $id === null ? null : Customer::where('uuid', $id)->firstOrFail();
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $today = Carbon::now(config('inventory.timezone'))->toDateString();

        return [
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'uuid', Rule::exists('customers', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'sold_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today],
            'note' => ['nullable', 'string', 'max:500'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'paid_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'payment_method' => ['nullable', Rule::in(SalesPayment::METHODS)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.unit_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'kho bán', 'customer_id' => 'khách hàng', 'sold_on' => 'ngày bán', 'note' => 'ghi chú', 'discount' => 'số tiền bớt',
            'paid_amount' => 'số tiền thu', 'payment_method' => 'hình thức thu', 'lines' => 'danh sách hàng',
            'lines.*.product_id' => 'sản phẩm', 'lines.*.unit_id' => 'đơn vị', 'lines.*.quantity' => 'số lượng', 'lines.*.unit_price' => 'đơn giá bán',
        ];
    }
}
