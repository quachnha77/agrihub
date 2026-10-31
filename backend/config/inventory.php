<?php

/**
 * Kho (xem docs/DATABASE_DESIGN.md mục 3).
 */
return [

    /** Múi giờ để xác định "hôm nay" khi so hạn dùng của lô (hạn dùng là ngày cuối cùng còn dùng được). */
    'timezone' => env('INVENTORY_TIMEZONE') ?: 'Asia/Ho_Chi_Minh',

    /** Lô còn hạn dùng không quá số ngày này được coi là "sắp hết hạn" (có thể đổi bằng tham số `days` khi tra cứu). */
    'expiry_warning_days' => max(1, (int) (env('INVENTORY_EXPIRY_WARNING_DAYS') ?: 60)),
];
