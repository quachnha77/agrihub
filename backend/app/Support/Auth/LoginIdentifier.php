<?php

namespace App\Support\Auth;

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

    /** Khóa giới hạn tốc độ: "A@x.com" và "a@x.com", hay "0912 345 678" và "+84912345678", cùng một khóa. */
    public static function throttleKey(?string $raw): string
    {
        $id = self::parse($raw);

        return $id === null ? 'raw:'.mb_strtolower(trim((string) $raw)) : "{$id->type}:{$id->value}";
    }
}
