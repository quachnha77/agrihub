<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bắt đầu một đợt kiểm kê (POST /stocktakes): chọn kho và (tùy chọn) ghi chú. Quy tắc nghiệp vụ (kho còn hoạt động, chưa có đợt đang đếm) do StocktakeService kiểm tra. */
class StartStocktakeRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjustment.create') ?? false;
    }

    /** Kho được chọn (đã kiểm tra tồn tại trong doanh nghiệp, chưa xóa). */
    public function warehouse(): Warehouse
    {
        return Warehouse::where('uuid', $this->validated('warehouse_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'uuid')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['warehouse_id' => 'kho', 'note' => 'ghi chú'];
    }
}
