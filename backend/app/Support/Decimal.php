<?php

namespace App\Support;

/**
 * Số thập phân chính xác (chuỗi + BCMath) cho tồn kho và giá vốn: không dùng số thực nên không có sai số làm tròn.
 * Làm tròn "half up" (ra xa số 0 khi đúng một nửa). Giá trị đầu vào là chuỗi dạng "12.500" hoặc số từ JSON.
 */
final class Decimal
{
    /** Số bất kỳ (int/float/chuỗi số) thành chuỗi thập phân thường, tối đa 6 chữ số thập phân, không dùng ký hiệu khoa học. */
    public static function from(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : number_format((float) $value, 6, '.', '');

        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            return '0';
        }

        return $text;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 9);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 9);
    }

    public static function mul(string $a, string $b, int $scale): string
    {
        return self::round(bcmul($a, $b, $scale + 9), $scale);
    }

    public static function div(string $a, string $b, int $scale): string
    {
        return self::round(bcdiv($a, $b, $scale + 9), $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, 9);
    }

    /** Làm tròn half-up về $scale chữ số thập phân. */
    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', 12) < 0 ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }

    /** Số tiền (đồng) làm tròn 2 chữ số thập phân. */
    public static function money(string $value): string
    {
        return self::round($value, 2);
    }

    public static function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    public static function isNegative(string $value): bool
    {
        return bccomp($value, '0', 9) < 0;
    }

    public static function negate(string $value): string
    {
        return self::isNegative($value) ? self::abs($value) : bcmul($value, '-1', 9);
    }
}
