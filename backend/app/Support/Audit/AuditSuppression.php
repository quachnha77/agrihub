<?php

namespace App\Support\Audit;

use Closure;

/**
 * Tạm tắt việc tự ghi audit của model (trait Auditable) cho các thao tác hệ thống hàng loạt đã có sự kiện tổng hợp,
 * ví dụ tạo 7 vai trò hệ thống cho Tenant mới. KHÔNG dùng để che giấu hành động của người dùng.
 */
final class AuditSuppression
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
