<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
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
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\-\s().]{6,30}$/'],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'reason' => ['nullable', 'string', 'max:255'],
            // Email là định danh đăng nhập: không đổi qua API này.
            'email' => ['prohibited'],
        ];
    }
}
