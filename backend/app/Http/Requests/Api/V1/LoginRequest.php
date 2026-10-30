<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\LoginIdentifier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Đăng nhập bằng email HOẶC số điện thoại (trường `login`; `email` vẫn được nhận như tên cũ của cùng trường). */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('login') && $this->has('email')) {
            $this->merge(['login' => $this->input('email')]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => [
                'required', 'string', 'max:255',
                fn (string $attribute, mixed $value, Closure $fail) => LoginIdentifier::parse(is_string($value) ? $value : null) === null
                    ? $fail('Vui lòng nhập email hoặc số điện thoại hợp lệ.')
                    : null,
            ],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => 'email hoặc số điện thoại', 'password' => 'mật khẩu'];
    }

    public function identifier(): LoginIdentifier
    {
        return LoginIdentifier::parse($this->validated('login'));
    }
}
