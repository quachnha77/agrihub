<?php

/**
 * Mỗi doanh nghiệp (Tenant) chạy trên MỘT subdomain: <slug>.<TENANT_BASE_DOMAIN> (vd. nong-phat.agrihub.vn).
 * Quản trị nền tảng (Super Admin) ở <TENANT_PLATFORM_LABELS>.<TENANT_BASE_DOMAIN> (mặc định admin.agrihub.vn) hoặc chính tên miền gốc.
 * Xem docs/MULTI_TENANT_DESIGN.md mục "Subdomain".
 *
 * Host chỉ dùng để (1) chọn đúng doanh nghiệp ở trang đăng nhập và (2) từ chối đăng nhập/phiên sai địa chỉ. Quyền truy cập dữ liệu
 * vẫn dựa trên người dùng đã xác thực (không bao giờ tin Host): ai gửi Host giả cũng chỉ thấy được dữ liệu của chính tài khoản mình.
 */
$domain = strtolower(trim((string) env('TENANT_BASE_DOMAIN'), " \t./"));

return [

    /**
     * Tên miền gốc (không có http://, không có cổng; vd. agrihub.vn hay localhost khi phát triển).
     * Để trống = TẮT tính năng (một địa chỉ cho tất cả, như trước đây).
     */
    'base_domain' => $domain === '' ? null : $domain,

    /** Các nhãn subdomain dành cho quản trị nền tảng (ngoài tên miền gốc). */
    'platform_labels' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) (env('TENANT_PLATFORM_LABELS') ?: 'admin')))))),

    /** Dùng khi dựng đường dẫn đăng nhập của doanh nghiệp (hiển thị cho Super Admin và khi đăng nhập nhầm địa chỉ). */
    'scheme' => env('TENANT_URL_SCHEME') ?: (env('APP_ENV') === 'production' ? 'https' : 'http'),

    /** Cổng của địa chỉ công khai khi khác 80/443 (vd. 5173 lúc phát triển với Vite). */
    'url_port' => env('TENANT_URL_PORT') ?: null,

    /** Tên máy chủ nội bộ vẫn được tin dù không thuộc tên miền gốc (regex, ngăn cách bằng dấu phẩy; vd. cân bằng tải gọi bằng IP). */
    'extra_trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANT_EXTRA_TRUSTED_HOSTS', ''))))),
];
