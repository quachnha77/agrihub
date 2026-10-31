<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Sao chép sản phẩm sang chi nhánh khác. Quyền xem sản phẩm nguồn và tạo ở chi nhánh đích do controller kiểm tra. */
class CopyProductsRequest extends FormRequest
{
    /** Không có quyền tạo sản phẩm thì không sao chép được: 403 ngay, trước cả bước kiểm tra dữ liệu. */
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.product.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('catalog.max_copy')],
            'product_ids.*' => ['uuid', 'distinct'],
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'uuid')
                ->where('tenant_id', app(TenantContext::class)->tenantId())->where('status', 'active')->whereNull('deleted_at')],
        ];
    }

    public function targetBranch(): Branch
    {
        return Branch::where('uuid', $this->validated('branch_id'))->firstOrFail();
    }

    public function attributes(): array
    {
        return ['product_ids' => 'danh sách sản phẩm', 'product_ids.*' => 'sản phẩm', 'branch_id' => 'chi nhánh đích'];
    }
}
