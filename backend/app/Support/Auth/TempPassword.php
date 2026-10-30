<?php

namespace App\Support\Auth;

/**
 * Sinh mật khẩu tạm ngẫu nhiên an toàn (CSPRNG), tránh ký tự dễ nhầm (0/O, 1/l/I) để đọc/đọc qua điện thoại.
 * Luôn có chữ thường, chữ hoa và số nên thỏa chính sách mật khẩu. Chỉ hiển thị MỘT lần cho người cấp, không lưu bản rõ.
 */
final class TempPassword
{
    public static function generate(int $length = 16): string
    {
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $lower.$upper.$digits;

        $chars = [self::pick($lower), self::pick($upper), self::pick($digits)];
        while (count($chars) < max($length, 12)) {
            $chars[] = self::pick($all);
        }

        for ($i = count($chars) - 1; $i > 0; $i--) { // Fisher–Yates với random_int
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
