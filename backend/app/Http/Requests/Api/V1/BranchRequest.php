<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Dùng chung cho tạo (POST) và cập nhật (PATCH) chi nhánh. Quyền do Policy kiểm tra ở controller. */
class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $presence = $creating ? 'required' : 'sometimes';
        $branch = $this->route('branch');

        return [
            'code' => [$presence, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->tenantId())
                    ->ignore($branch?->id)],
            'name' => [$presence, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'mã chi nhánh', 'name' => 'tên chi nhánh', 'phone' => 'số điện thoại', 'address' => 'địa chỉ', 'status' => 'trạng thái'];
    }
}
