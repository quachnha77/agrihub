<?php

/**
 * Danh mục sản phẩm (Giai đoạn 2). Xem docs/DATABASE_DESIGN.md.
 */
return [

    /** Đơn vị tính thông dụng tạo sẵn cho mỗi doanh nghiệp mới; doanh nghiệp tự thêm/sửa sau. */
    'default_units' => ['Kg', 'Bao', 'Gói', 'Chai', 'Lọ', 'Lít', 'Can', 'Thùng', 'Hộp', 'Cái', 'Túi', 'Bịch'],

    /** Số đơn vị bán tối đa của một sản phẩm (đơn vị cơ sở + các đơn vị quy đổi). */
    'max_units_per_product' => 8,

    /** Số sản phẩm tối đa cho một lần sao chép sang chi nhánh khác. */
    'max_copy' => 200,
];
