<?php

/**
 * Cấu hình cấp nền tảng AgriHub (onboarding Tenant, phiên hỗ trợ, chính sách mật khẩu).
 */
return [

    /** Slug không được dùng làm định danh Tenant (tránh trùng đường dẫn/ứng dụng của hệ thống, ví dụ /shop/{slug}). */
    'reserved_slugs' => [
        'admin', 'agrihub', 'api', 'app', 'assets', 'auth', 'billing', 'dashboard', 'docs', 'help', 'login', 'logout',
        'platform', 'portal', 'register', 'shop', 'static', 'status', 'support', 'system', 'www',
    ],

    /** Chi nhánh đầu tiên được tạo khi onboarding nếu không chỉ định. */
    'default_branch' => ['code' => 'CN-01', 'name' => 'Chi nhánh chính'],

    /**
     * Phiên hỗ trợ (impersonation): Platform Admin xem dữ liệu của một Tenant ở chế độ CHỈ ĐỌC,
     * bắt buộc có lý do, tự hết hạn, mọi truy cập đều được ghi audit và hiển thị cho chính Tenant.
     */
    'support_session' => [
        'default_minutes' => 30,
        'max_minutes' => 120,
        'min_reason_length' => 10,
    ],

    'rate_limits' => [
        /** Số lần/phút/người dùng cho thao tác nhạy cảm (đổi mật khẩu, cấp mật khẩu tạm, tạo người dùng/doanh nghiệp). */
        'sensitive' => 10,
    ],

    'password' => [
        'min_length' => 10,
        /** Mật khẩu quá phổ biến (so khớp không phân biệt hoa thường). */
        'weak_list' => [
            'password123', 'password1234', '1234567890', '12345678910', 'qwerty12345', 'abcdefghij1',
            'matkhau123', 'matkhau1234', 'agrihub123', 'agrihub1234', 'admin12345', 'iloveyou123',
        ],
    ],
];
