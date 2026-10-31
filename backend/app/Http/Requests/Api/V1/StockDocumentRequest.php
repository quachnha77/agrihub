<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ghi một phiếu kho (POST /stock/documents): nhập kho (`receipt`), xuất kho (`issue`), điều chỉnh (`adjustment`) hoặc tồn đầu kỳ (`opening`).
 * Đây chỉ kiểm tra HÌNH DẠNG dữ liệu; quy tắc kho (thuộc chi nhánh, lô, đủ tồn...) do StockService kiểm tra và báo theo từng dòng.
 * Số lượng nhập theo ĐƠN VỊ người dùng chọn (`unit_id`); điều chỉnh cho phép số âm (giảm tồn).
 */
class StockDocumentRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu (quyền theo loại phiếu; loại lạ được 422 sau đó). */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return $this->input('type') === StockDocument::ADJUSTMENT ? $user->can('inventory.adjustment.create') : $user->can('inventory.movement.create');
    }

    /** Kho được chọn (đã kiểm tra tồn tại trong doanh nghiệp, chưa xóa). */
    public function warehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('warehouse_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $costRequired = in_array($this->input('type'), [StockDocument::OPENING, StockDocument::RECEIPT], true);
        $quantity = $this->input('type') === StockDocument::ADJUSTMENT
            ? ['required', 'numeric', 'decimal:0,3', 'not_in:0', 'between:-99999999,99999999']
            : ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999'];

        return [
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'type' => ['required', Rule::in(StockDocument::TYPES)],
            'reason' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.unit_id' => ['required', 'uuid'],
            'lines.*.quantity' => $quantity,
            'lines.*.unit_cost' => [$costRequired ? 'required' : 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'lines.*.batch_id' => ['nullable', 'uuid'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/ -]*$/'],
            'lines.*.manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expires_at' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'kho', 'type' => 'loại phiếu', 'reason' => 'lý do', 'note' => 'ghi chú', 'lines' => 'danh sách hàng',
            'lines.*.product_id' => 'sản phẩm', 'lines.*.unit_id' => 'đơn vị', 'lines.*.quantity' => 'số lượng', 'lines.*.unit_cost' => 'đơn giá nhập',
            'lines.*.batch_id' => 'lô', 'lines.*.batch_no' => 'số lô', 'lines.*.manufactured_at' => 'ngày sản xuất', 'lines.*.expires_at' => 'hạn dùng',
        ];
    }
}
