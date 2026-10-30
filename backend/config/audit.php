<?php

/**
 * Cấu hình Audit Log (xem docs/MONITORING_LOGGING.md).
 */
return [

    /** Số tháng giữ log. Dữ liệu tài chính nên giữ >= 24 tháng. */
    'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    /** Sàn an toàn: lệnh bảo trì từ chối xóa nếu retention nhỏ hơn giá trị này. */
    'min_retention_months' => 12,

    /**
     * Cho phép lệnh bảo trì XÓA vĩnh viễn các phân vùng quá hạn. Mặc định TẮT để không mất dữ liệu ngoài ý muốn;
     * chỉ bật sau khi đã có backup/lưu trữ lâu dài cho audit log.
     */
    'purge_enabled' => (bool) env('AUDIT_PURGE_ENABLED', false),

    /** Số tháng tạo sẵn phân vùng phía trước. */
    'partitions_ahead' => 3,

    /** Thuộc tính model không bao giờ vào before/after. */
    'ignore_attributes' => ['created_at', 'updated_at', 'remember_token'],

    /** Khóa (không phân biệt hoa thường) mà giá trị luôn bị thay bằng [REDACTED]. */
    'sensitive_key_pattern' => '/(password|passwd|passphrase|^pass$|^pwd$|secret|token|authorization|api[_-]?key|private[_-]?key|^otp$|[_-]otp$|recovery[_-]?code|cvv|card[_-]?number|credential)/i',

    'max_string_length' => 2000,

    'max_depth' => 6,
];
