<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Auth\LoginIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/** Quên mật khẩu / quên tài khoản: người dùng gõ email hoặc số điện thoại của tài khoản. */
class RecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:255', LoginIdentifier::rule()]];
    }

    public function attributes(): array
    {
        return ['login' => 'email hoặc số điện thoại'];
    }

    public function identifier(): LoginIdentifier
    {
        return LoginIdentifier::parse($this->validated('login'));
    }
}
