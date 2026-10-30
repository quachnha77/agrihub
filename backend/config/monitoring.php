<?php

// Khóa .env để trống hoặc không có => dùng mặc định; "0" là giá trị hợp lệ nên không dùng toán tử ?:.
$int = static fn (string $key, int $default): int => in_array($raw = env($key), [null, ''], true) ? $default : (int) $raw;

/**
 * Giám sát hạ tầng (xem docs/MONITORING_LOGGING.md mục 3). Ngưỡng đổi bằng biến môi trường, không cần sửa code.
 */
return [

    /** Gửi cảnh báo hạ tầng cho Super Admin. Mặc định chỉ bật ở production (dev/test không tự gửi). */
    'alerts_enabled' => filter_var(env('MONITOR_ALERTS_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? env('APP_ENV') === 'production',

    /** Email nhận cảnh báo hạ tầng NGOÀI các Super Admin (gộp với BACKUP_ALERT_EMAILS). Nên là hộp thư ngoài hệ thống. */
    'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_ALERT_EMAILS', ''))))),

    /**
     * Giám sát từ BÊN NGOÀI (dead-man's switch): mỗi phút, khi hệ thống không ở mức nghiêm trọng, máy chủ gọi URL này
     * (Healthchecks.io, Uptime Kuma "push"...). Dịch vụ bên ngoài báo khi KHÔNG còn nhận được tín hiệu — kể cả khi cả máy
     * chủ, scheduler hay queue đã chết và không còn ai tự gửi được cảnh báo nào.
     */
    'ping_url' => env('MONITOR_PING_URL') ?: null,
    /** Tùy chọn: URL báo "đang hỏng" (vd. Healthchecks.io `.../fail`) gọi khi ở mức nghiêm trọng, thay vì chỉ ngừng gọi. */
    'ping_fail_url' => env('MONITOR_PING_FAIL_URL') ?: null,

    /** Mã bí mật cho GET /api/v1/health/monitor (cho công cụ giám sát chủ động hỏi). Để trống = tắt endpoint. */
    'token' => env('MONITOR_TOKEN') ?: null,

    /** Nhắc lại cảnh báo đang tồn tại sau số giờ này (theo mức độ). */
    'remind_hours' => [
        'critical' => max(1, $int('MONITOR_REMIND_CRITICAL_HOURS', 6)),
        'warning' => max(1, $int('MONITOR_REMIND_WARNING_HOURS', 24)),
    ],

    'thresholds' => [
        /** Tín hiệu sống của scheduler / queue worker (mỗi phút một lần): quá số phút này là cảnh báo / nghiêm trọng. */
        'heartbeat_warn_minutes' => max(2, $int('MONITOR_HEARTBEAT_WARN_MINUTES', 5)),
        'heartbeat_critical_minutes' => max(3, $int('MONITOR_HEARTBEAT_CRITICAL_MINUTES', 15)),

        /** Công việc chờ lâu nhất trong hàng đợi (phút) và số công việc chờ. */
        'queue_oldest_warn_minutes' => max(1, $int('MONITOR_QUEUE_OLDEST_WARN_MINUTES', 10)),
        'queue_oldest_critical_minutes' => max(2, $int('MONITOR_QUEUE_OLDEST_CRITICAL_MINUTES', 30)),
        'queue_backlog_warn' => max(1, $int('MONITOR_QUEUE_BACKLOG_WARN', 200)),

        /** Công việc lỗi trong 24 giờ qua. */
        'failed_jobs_warn' => max(1, $int('MONITOR_FAILED_JOBS_WARN', 1)),
        'failed_jobs_critical' => max(2, $int('MONITOR_FAILED_JOBS_CRITICAL', 10)),

        /** Dung lượng đĩa đã dùng (%). */
        'disk_warn_percent' => min(99, max(1, $int('MONITOR_DISK_WARN_PERCENT', 80))),
        'disk_critical_percent' => min(100, max(2, $int('MONITOR_DISK_CRITICAL_PERCENT', 90))),

        /** Độ trễ truy vấn đơn giản tới database (ms) và tỉ lệ kết nối đang dùng (%). */
        'db_latency_warn_ms' => max(1, $int('MONITOR_DB_LATENCY_WARN_MS', 500)),
        'db_connections_warn_percent' => min(99, max(1, $int('MONITOR_DB_CONNECTIONS_WARN_PERCENT', 80))),
        'db_connections_critical_percent' => min(100, max(2, $int('MONITOR_DB_CONNECTIONS_CRITICAL_PERCENT', 95))),
    ],
];
