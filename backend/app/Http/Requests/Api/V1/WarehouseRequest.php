<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tạo (POST) và sửa (PATCH) kho. `branch_id` chỉ khi tạo (kho không đổi chi nhánh vì tồn và sổ kho gắn với chi nhánh);
 * mã kho duy nhất trong chi nhánh, không phân biệt hoa/thường.
 */
class WarehouseRequest extends FormRequest
{
    /** Người không có quyền nhận 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'inventory.warehouse.create' : 'inventory.warehouse.update') ?? false;
    }

    /** Chi nhánh của kho: chi nhánh được chọn khi tạo, hoặc chi nhánh hiện tại của kho khi sửa. */
    public function branch(): Branch
    {
        $warehouse = $this->route('warehouse');

        return $warehouse instanceof Warehouse
            ? Branch::findOrFail($warehouse->branch_id)
            : Branch::where('uuid', $this->validated('branch_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'branch_id' => [$creating ? 'required' : 'prohibited', 'uuid', Rule::exists('branches', 'uuid')->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('deleted_at')],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $this->uniqueCode()],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([Warehouse::STATUS_ACTIVE, Warehouse::STATUS_INACTIVE])],
        ];
    }

    /** Mã kho duy nhất trong chi nhánh (kho đã xóa mềm không giữ mã). */
    private function uniqueCode(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $warehouse = $this->route('warehouse');
            $branchUuid = $this->input('branch_id');
            $branchId = $warehouse instanceof Warehouse
                ? $warehouse->branch_id
                : (is_string($branchUuid) && Str::isUuid($branchUuid) ? Branch::where('uuid', $branchUuid)->value('id') : null);

            if (! is_string($value) || $branchId === null) {
                return;
            }

            $taken = Warehouse::where('branch_id', $branchId)->whereRaw('lower(code) = ?', [mb_strtolower($value)])
                ->when($warehouse instanceof Warehouse, fn ($q) => $q->where('id', '!=', $warehouse->id))->exists();

            if ($taken) {
                $fail('Mã kho này đã được dùng trong chi nhánh.');
            }
        };
    }

    public function attributes(): array
    {
        return ['branch_id' => 'chi nhánh', 'code' => 'mã kho', 'name' => 'tên kho', 'address' => 'địa chỉ', 'status' => 'trạng thái'];
    }
}
