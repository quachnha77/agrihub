<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** Đặt mật khẩu mới bằng mã trong email. Mật khẩu theo đúng chính sách chung (PasswordPolicy). */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => array_merge(['required', 'confirmed'], PasswordPolicy::rules(fn () => $this->input('email'))),
        ];
    }

    public function messages(): array
    {
        return ['password.confirmed' => 'Xác nhận mật khẩu không khớp.'];
    }

    public function attributes(): array
    {
        return ['email' => 'email', 'token' => 'mã đặt lại', 'password' => 'mật khẩu'];
    }
}
