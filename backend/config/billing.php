<?php

/**
 * Gói dịch vụ & thuê bao. Các con số dưới đây là GIÁ TRỊ MẶC ĐỊNH GIẢ ĐỊNH, chưa được chủ sản phẩm chốt.
 */
return [

    /** Gói gán cho Tenant mới khi onboarding (key trong bảng plans). */
    'default_plan' => 'standard',

    'trial_days' => 14,

    /** Số người dùng trong thời gian dùng thử. */
    'trial_seats' => 5,

    /**
     * Số ngày ân hạn sau khi hết hạn: vẫn dùng đầy đủ. Hết ân hạn thì chuyển sang CHỈ ĐỌC (không khóa, không mất dữ liệu).
     */
    'grace_days' => 7,

    /** Mốc nhắc gia hạn (số ngày còn lại) cho banner trong ứng dụng và danh sách việc cần liên hệ của nhân viên AgriHub. */
    'reminder_days' => [15, 7, 3, 1],

    'payment_methods' => ['bank_transfer', 'momo', 'cash', 'other'],

    'max_renew_periods' => 24,
];
