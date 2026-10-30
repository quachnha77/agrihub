<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use App\Rules\UniquePhone;
use App\Rules\VietnamesePhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', new VietnamesePhone, new UniquePhone($this->route('user')?->tenant_id, $this->route('user')?->id)],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'reason' => ['nullable', 'string', 'max:255'],
            // Email là định danh đăng nhập: không đổi qua API này.
            'email' => ['prohibited'],
        ];
    }
}
