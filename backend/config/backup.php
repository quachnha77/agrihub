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
