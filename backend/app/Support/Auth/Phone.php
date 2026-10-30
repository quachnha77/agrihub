<?php

namespace App\Support\Auth;

/**
 * Số điện thoại Việt Nam dạng chuẩn để lưu và so khớp: 10–11 chữ số, bắt đầu bằng 0 (vd. 0912345678).
 * Chấp nhận khi nhập: khoảng trắng, dấu chấm, gạch ngang, ngoặc và đầu số quốc tế (+84 / 84).
 */
final class Phone
{
    /** @return string|null số chuẩn, hoặc null nếu không phải số điện thoại Việt Nam hợp lệ */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/[\s.\-()]/', '', trim($raw));

        if ($digits === null || ! preg_match('/^\+?\d+$/', $digits)) {
            return null;
        }

        if (str_starts_with($digits, '+84')) {
            $digits = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '84') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^0[2-9]\d{8,9}$/', $digits) ? $digits : null;
    }

    /** Che phần giữa khi hiển thị trong email (0912345678 -> 091****678). */
    public static function mask(string $phone): string
    {
        $length = strlen($phone);

        return $length <= 6 ? $phone : substr($phone, 0, 3).str_repeat('*', $length - 6).substr($phone, -3);
    }
}
