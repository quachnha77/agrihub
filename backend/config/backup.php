<?php

// Khóa .env để trống hoặc không có => dùng mặc định; "0" là giá trị hợp lệ (nghĩa là tắt) nên không dùng toán tử ?:.
$int = static fn (string $key, int $default): int => in_array($raw = env($key), [null, ''], true) ? $default : (int) $raw;

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
     * Sao lưu TỰ ĐỘNG hằng ngày dữ liệu nghiệp vụ của TỪNG Tenant (loại `auto`; chỉ Super Admin xem/tải/khôi phục, doanh nghiệp không có chức năng này).
     * Chạy qua queue worker. Mặc định chỉ bật ở production.
     */
    'tenant_auto' => [
        'enabled' => filter_var(env('BACKUP_TENANT_AUTO_ENABLED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? env('APP_ENV') === 'production',
        'at' => env('BACKUP_TENANT_AT') ?: '02:00',
        /** Giữ mọi bản tự động trong số ngày này; bản thủ công, tải lên và điểm quay lại không bao giờ tự xóa. */
        'keep_days' => max(1, $int('BACKUP_TENANT_KEEP_DAYS', 30)),
        /** Luôn giữ ít nhất số bản tự động mới nhất của mỗi Tenant (lịch ngừng chạy cũng không bị xóa sạch). */
        'min_keep' => max(1, $int('BACKUP_TENANT_MIN_KEEP', 3)),
    ],

    /**
     * Thông báo về trạng thái sao lưu (trong ứng dụng + email cho Super Admin). Xem docs/BACKUP_DISASTER_RECOVERY.md mục 9.
     */
    'alerts' => [
        /** Múi giờ dùng để hiển thị giờ trong nội dung thông báo (hệ thống lưu giờ UTC). */
        'timezone' => env('BACKUP_ALERT_TIMEZONE') ?: 'Asia/Ho_Chi_Minh',

        /** Cùng một cảnh báo (vd. "quá hạn sao lưu") chỉ gửi lại sau số giờ này nếu vấn đề vẫn còn. */
        'dedupe_hours' => max(1, $int('BACKUP_ALERT_DEDUPE_HOURS', 24)),

        /**
         * Email nhận cảnh báo NGOÀI các Super Admin (ngăn cách bằng dấu phẩy) — nên là hộp thư ngoài hệ thống,
         * vì cảnh báo trong ứng dụng không đọc được khi cả hệ thống ngừng hoạt động.
         *
         * @var list<string>
         */
        'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_ALERT_EMAILS', ''))))),

        /** Cảnh báo "quá hạn" khi bản sao lưu hoàn tất mới nhất cũ hơn số giờ này (lịch chạy mỗi 24 giờ). */
        'overdue_hours' => max(1, $int('BACKUP_ALERT_OVERDUE_HOURS', 36)),

        /** Cảnh báo khi quá số ngày chưa có lần khôi phục thử nào đạt (lịch chạy mỗi tuần). */
        'verify_overdue_days' => max(1, $int('BACKUP_ALERT_VERIFY_OVERDUE_DAYS', 10)),

        /** Nhắc tải một bản sao lưu ra nơi lưu riêng khi quá số ngày chưa có lần tải nào. 0 = không nhắc. */
        'offsite_days' => max(0, $int('BACKUP_ALERT_OFFSITE_DAYS', 7)),
    ],

    /**
     * Sao lưu TOÀN HỆ THỐNG (mọi Tenant) bằng pg_dump — dành cho khôi phục thảm họa. Khôi phục chỉ qua dòng lệnh;
     * Super Admin được tải file về để lưu riêng (nhập lại mật khẩu, liên kết dùng một lần).
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
        // Danh mục sản phẩm (cha → con). Giai đoạn 2 sẽ thêm tiếp: customers, suppliers, kho, ... (xem IMPLEMENTATION_PLAN.md)
        'units',
        'categories',
        'brands',
        'products',
        'product_units',
        // Khách hàng và nhà cung cấp (cha → con).
        'customer_groups',
        'suppliers',
        'supplier_contacts',
        'customers',
        'customer_addresses',
        'customer_contacts',
        // Kho (cha → con): kho, lô, giá vốn, phiếu chuyển kho (phiếu kho tham chiếu nó), phiếu kho và sổ kho (bất biến, chỉ được xóa trong lúc khôi phục), số dư.
        'warehouses',
        'batches',
        'product_costs',
        'stock_transfers',
        'stock_documents',
        'stock_movements',
        'stock_balances',
        // Kiểm kê (cha → con): đợt tham chiếu kho và phiếu điều chỉnh; dòng đếm tham chiếu đợt, sản phẩm và lô.
        'stocktakes',
        'stocktake_lines',
        // Mua hàng (cha → con): phiếu nhập hàng tham chiếu nhà cung cấp, kho và phiếu nhập kho; dòng và lần trả tiền tham chiếu phiếu.
        'purchase_receipts',
        'purchase_receipt_lines',
        'purchase_payments',
        // Bán hàng (cha → con): phiếu bán tham chiếu khách, kho và phiếu xuất kho; dòng và lần thu tiền tham chiếu phiếu.
        'sales_orders',
        'sales_order_lines',
        'sales_payments',
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
        'notices' => 'Thông báo gửi cho từng người dùng (kết quả vận hành): khôi phục dữ liệu cũ không được làm sống lại thông báo cũ.',
    ],
];
