<?php

namespace App\Support\Auth;

use Closure;

/** Thứ người dùng gõ vào ô đăng nhập: một email hoặc một số điện thoại (đã chuẩn hóa để tra cứu và giới hạn tốc độ). */
final class LoginIdentifier
{
    private function __construct(
        /** 'email' | 'phone' — cũng là tên cột trong bảng users. */
        public readonly string $type,
        public readonly string $value,
    ) {}

    public static function parse(?string $raw): ?self
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '@')) {
            return filter_var($raw, FILTER_VALIDATE_EMAIL) ? new self('email', mb_strtolower($raw)) : null;
        }

        $phone = Phone::normalize($raw);

        return $phone === null ? null : new self('phone', $phone);
    }

    /** Rule kiểm tra ô "email hoặc số điện thoại" (đăng nhập, quên mật khẩu, quên tài khoản). */
    public static function rule(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => self::parse(is_string($value) ? $value : null) === null
            ? $fail('Vui lòng nhập email hoặc số điện thoại hợp lệ.')
            : null;
    }

    /**
     * Khóa giới hạn tốc độ: "A@x.com" và "a@x.com", hay "0912 345 678" và "+84912345678", cùng một khóa.
     * Số điện thoại kèm phạm vi (doanh nghiệp) vì cùng số ở hai doanh nghiệp là hai tài khoản khác nhau.
     */
    public static function throttleKey(?string $raw, ?LoginScope $scope = null): string
    {
        $id = self::parse($raw);

        if ($id === null) {
            return 'raw:'.mb_strtolower(trim((string) $raw));
        }

        return $id->type === 'phone' && $scope !== null ? "phone:{$scope->key()}:{$id->value}" : "{$id->type}:{$id->value}";
    }
}
