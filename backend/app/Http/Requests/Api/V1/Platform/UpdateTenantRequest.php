<?php

namespace App\Http\Requests\Api\V1\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s().]{6,30}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-\d{3})?$/'],
            // slug là định danh công khai (vd. /shop/{slug}) nên không đổi sau khi tạo; trạng thái đổi qua suspend/activate.
            'slug' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
