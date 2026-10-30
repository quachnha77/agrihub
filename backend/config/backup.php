<?php

/**
 * Sao lưu / khôi phục (xem docs/BACKUP_DISASTER_RECOVERY.md).
 */
return [

    /** Disk lưu file sao lưu (riêng tư, ngoài thư mục public). Có thể đổi sang S3 ở production. */
    'disk' => env('BACKUP_DISK', 'backups'),

    /**
     * Khóa mã hóa file sao lưu (chuỗi bất kỳ ≥ 32 ký tự hoặc `base64:...`). Để trống thì suy ra từ APP_KEY.
     * Mất khóa = không đọc lại được bản sao lưu: cất khóa TÁCH khỏi nơi lưu file sao lưu.
     */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    'format_version' => 1,

    /** Kích thước tối đa file tải lên khi Import (MB) và dung lượng tối đa sau khi giải nén (MB). */
    'max_upload_mb' => (int) env('BACKUP_MAX_UPLOAD_MB', 512),
    'max_uncompressed_mb' => (int) env('BACKUP_MAX_UNCOMPRESSED_MB', 4096),

    /** Cờ "đang khôi phục" quá số phút này thì coi là treo và không còn chặn Tenant. */
    'restore_lock_minutes' => 120,

    /**
     * Sao lưu TOÀN HỆ THỐNG (mọi Tenant) bằng pg_dump — dành cho khôi phục thảm họa, chỉ khôi phục qua dòng lệnh.
     *
     * Kết nối sao lưu phải dùng một role RIÊNG, không phải role của ứng dụng: role đó cần BYPASSRLS (hoặc superuser) để
     * pg_dump đọc được dữ liệu mọi Tenant (RLS đang bật FORCE), và CREATEDB để kiểm thử khôi phục vào database tạm.
     * Ứng dụng KHÔNG BAO GIỜ nhận quyền này. Xem docs/BACKUP_DISASTER_RECOVERY.md mục 8.
     */
    'platform' => [
        /** Bật lịch tự động. Mặc định chỉ bật ở production để dev/test không tự chạy. */
        'enabled' => filter_var(env('BACKUP_PLATFORM_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? env('APP_ENV') === 'production',
        'at' => env('BACKUP_PLATFORM_AT') ?: '01:30',
        'prune_at' => env('BACKUP_PRUNE_AT') ?: '03:30',
        /** Kiểm thử khôi phục tự động mỗi tuần (0 = Chủ nhật … 6 = Thứ bảy). */
        'verify_day' => (int) (env('BACKUP_VERIFY_DAY') ?: 0),
        'verify_at' => env('BACKUP_VERIFY_AT') ?: '04:30',

        /** Thư mục chứa pg_dump / pg_restore. Để trống = tìm trong PATH. */
        'pg_bin' => env('BACKUP_PG_BIN'),

        // Khóa để trống trong .env (vd. sao chép từ .env.example) được coi như chưa đặt => dùng giá trị của kết nối ứng dụng.
        'host' => env('BACKUP_DB_HOST') ?: env('DB_HOST', '127.0.0.1'),
        'port' => (int) (env('BACKUP_DB_PORT') ?: env('DB_PORT', 5432)),
        'database' => env('BACKUP_DB_DATABASE') ?: env('DB_DATABASE'),
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),
        'sslmode' => env('BACKUP_DB_SSLMODE'),

        /**
         * Bảng chỉ bỏ DỮ LIỆU (giữ cấu trúc) vì khôi phục lại sẽ gây hại: phiên đăng nhập và token cũ sống lại,
         * hàng đợi chạy lại việc đã làm, mã đặt lại mật khẩu cũ dùng được. Sau khôi phục mọi người đăng nhập lại.
         *
         * @var list<string>
         */
        'exclude_table_data' => [
            'sessions', 'password_reset_tokens', 'personal_access_tokens',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        ],

        /** Tiền tố bắt buộc của database tạm dùng khi kiểm thử khôi phục (chỉ database mang tiền tố này mới bị xóa). */
        'verify_prefix' => 'agrihub_verify_',

        /**
         * Giữ bản tự động: mọi bản trong `daily_days` ngày; sau đó mỗi tuần một bản trong `weekly_weeks` tuần;
         * sau đó mỗi tháng một bản trong `monthly_months` tháng. Luôn giữ ít nhất `min_keep` bản mới nhất
         * (lịch ngừng chạy thì không bị xóa sạch). Bản thủ công không bao giờ tự xóa.
         */
        'retention' => [
            'daily_days' => 30,
            'weekly_weeks' => 12,
            'monthly_months' => 12,
            'min_keep' => 3,
        ],

        /** Bản đang "chờ/đang chạy" quá số giờ này được coi là bị gián đoạn. */
        'stale_hours' => 12,
    ],

    /**
     * Các bảng dữ liệu NGHIỆP VỤ của Tenant được sao lưu / khôi phục, theo thứ tự cha → con (khóa ngoại).
     * Mỗi bảng phải có `id` và `tenant_id`. Module nghiệp vụ mới PHẢI thêm bảng của mình vào đây
     * (hoặc vào `excluded_tenant_tables` kèm lý do) — một test sẽ báo lỗi nếu có bảng `tenant_id` bị bỏ sót.
     * Khôi phục = xóa toàn bộ dòng của Tenant trong các bảng này rồi nạp lại từ bản sao lưu, trong MỘT transaction.
     *
     * @var list<string>
     */
    'tenant_tables' => [
        // Giai đoạn 2 sẽ thêm: customers, suppliers, products, ... (xem IMPLEMENTATION_PLAN.md)
    ],

    /**
     * Bảng có `tenant_id` nhưng cố ý KHÔNG nằm trong sao lưu/khôi phục theo Tenant (bảng => lý do).
     * Giá trị là lý do để người đọc hiểu, không dùng trong code.
     *
     * @var array<string, string>
     */
    'excluded_tenant_tables' => [
        'branches' => 'Danh mục nền, được tài khoản người dùng tham chiếu; quản lý qua API chi nhánh.',
        'users' => 'Tài khoản và mật khẩu: không đưa vào file tải xuống; quản lý qua API người dùng.',
        'user_branches' => 'Phân quyền chi nhánh của tài khoản (định danh).',
        'roles' => 'Phân quyền (định danh): khôi phục dữ liệu cũ không được làm đổi quyền hiện tại.',
        'role_permissions' => 'Phân quyền (định danh).',
        'user_roles' => 'Phân quyền (định danh).',
        'audit_logs' => 'Chỉ ghi thêm, không bao giờ bị ghi đè khi khôi phục.',
        'subscriptions' => 'Do nhân viên AgriHub quản lý, Tenant không được tự khôi phục về gói cũ.',
        'subscription_periods' => 'Sổ kỳ thuê bao, chỉ thêm.',
        'backups' => 'Chính danh sách sao lưu.',
        'restore_runs' => 'Nhật ký khôi phục.',
    ],
];
