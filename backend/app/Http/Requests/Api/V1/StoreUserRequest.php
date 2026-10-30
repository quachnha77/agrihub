<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\UniqueEmail;
use App\Rules\UniquePhone;
use App\Rules\VietnamesePhone;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** Tạo người dùng trong Tenant. Quyền do UserPolicy kiểm tra ở controller. */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueEmail],
            'phone' => ['nullable', 'string', new VietnamesePhone, new UniquePhone($this->user()?->tenant_id)],
            'password' => array_merge(['nullable'], PasswordPolicy::rules(fn () => $this->input('email'))),
            'role_ids' => ['nullable', 'array', 'max:20'],
            'role_ids.*' => ['uuid', 'distinct'],
            'branch_ids' => ['nullable', 'array', 'max:50'],
            'branch_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tên', 'email' => 'email', 'phone' => 'số điện thoại', 'password' => 'mật khẩu'];
    }
}
