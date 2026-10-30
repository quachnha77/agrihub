<?php

namespace App\Support\Auth;

use Closure;
use Illuminate\Validation\Rules\Password;

/** Chính sách mật khẩu dùng chung cho tạo người dùng, onboarding và đổi mật khẩu. */
final class PasswordPolicy
{
    /**
     * @param  string|Closure|null  $email  email của chủ tài khoản (hoặc closure lấy email lúc validate) để cấm mật khẩu chứa tên đăng nhập
     * @return list<mixed>
     */
    public static function rules(string|Closure|null $email = null): array
    {
        return [
            'string',
            'max:128',
            Password::min((int) config('platform.password.min_length'))->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail) use ($email): void {
                $password = mb_strtolower((string) $value);

                if (in_array($password, config('platform.password.weak_list'), true)) {
                    $fail('Mật khẩu quá phổ biến, hãy chọn mật khẩu khác.');

                    return;
                }

                $address = $email instanceof Closure ? $email() : $email;
                $local = is_string($address) ? mb_strtolower(explode('@', $address)[0]) : '';

                if (mb_strlen($local) >= 4 && str_contains($password, $local)) {
                    $fail('Mật khẩu không được chứa tên đăng nhập (email).');
                }
            },
        ];
    }
}
