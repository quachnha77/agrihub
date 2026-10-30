<?php

/**
 * Gói dịch vụ & thuê bao. Các con số dưới đây là GIÁ TRỊ MẶC ĐỊNH GIẢ ĐỊNH, chưa được chủ sản phẩm chốt.
 */
return [

    /** Gói gán cho Tenant mới khi onboarding (key trong bảng plans). */
    'default_plan' => 'standard',

    'trial_days' => 7,

    /** Số người dùng trong thời gian dùng thử. */
    'trial_seats' => 5,

    /**
     * Số ngày ân hạn sau khi hết hạn (vẫn dùng đầy đủ). Mặc định 0: hết hạn là DỪNG sử dụng (đăng nhập và API đều bị chặn,
     * dữ liệu được giữ nguyên) cho đến khi nhân viên AgriHub gia hạn hoặc chủ động mở lại (reopen).
     */
    'grace_days' => 0,

    /** Số ngày tối đa mỗi lần nhân viên AgriHub mở lại tạm thời cho Tenant đã hết hạn. */
    'max_reopen_days' => 30,

    /** Mốc nhắc gia hạn (số ngày còn lại) cho banner trong ứng dụng và danh sách việc cần liên hệ của nhân viên AgriHub. */
    'reminder_days' => [15, 7, 3, 1],

    'payment_methods' => ['bank_transfer', 'momo', 'cash', 'other'],

    'max_renew_periods' => 24,
];
