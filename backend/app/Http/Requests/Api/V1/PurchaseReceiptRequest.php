<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lập phiếu nhập hàng (POST /purchases): kho nhận, nhà cung cấp, các dòng hàng (đơn vị và đơn giá theo cách người dùng nhập), chi phí kèm theo và số tiền trả ngay.
 * Chỉ kiểm tra HÌNH DẠNG; quy tắc nghiệp vụ (kho còn hoạt động, kho đang kiểm kê, lô, trả không vượt tiền hàng...) do PurchaseService / StockService kiểm tra và báo theo ô.
 */
class PurchaseReceiptRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.order.create') ?? false;
    }

    public function warehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('warehouse_id'))->firstOrFail();
    }

    public function supplier(): Supplier
    {
        return Supplier::where('uuid', $this->validated('supplier_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $today = Carbon::now(config('inventory.timezone'))->toDateString();

        return [
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'supplier_ref' => ['nullable', 'string', 'max:50'],
            'received_on' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:'.$today],
            'note' => ['nullable', 'string', 'max:500'],
            'extra_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'paid_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'payment_method' => ['nullable', Rule::in(PurchasePayment::METHODS)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.unit_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999'],
            'lines.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/ -]*$/'],
            'lines.*.manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expires_at' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'kho nhận', 'supplier_id' => 'nhà cung cấp', 'supplier_ref' => 'số chứng từ nhà cung cấp', 'received_on' => 'ngày nhập', 'note' => 'ghi chú',
            'extra_cost' => 'chi phí kèm theo', 'paid_amount' => 'số tiền trả', 'payment_method' => 'hình thức trả', 'lines' => 'danh sách hàng',
            'lines.*.product_id' => 'sản phẩm', 'lines.*.unit_id' => 'đơn vị', 'lines.*.quantity' => 'số lượng', 'lines.*.unit_cost' => 'đơn giá nhập',
            'lines.*.batch_no' => 'số lô', 'lines.*.manufactured_at' => 'ngày sản xuất', 'lines.*.expires_at' => 'hạn dùng',
        ];
    }
}
