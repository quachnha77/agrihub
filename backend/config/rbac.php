<?php

/**
 * Catalog quyền và vai trò mặc định của AgriHub (nguồn: docs/RBAC_PERMISSION_MATRIX.md).
 *
 * Quyền = "<resource>.<action>", ví dụ "sales.order.create". Phạm vi dữ liệu: own < branch < tenant.
 * Chữ cái hành động: V view · C create · U update · D delete · A approve · E export · X cancel · R restore.
 *
 * Chỉ thêm/sửa catalog bằng code + migration/lệnh `agrihub:sync-permissions`; Tenant không tự tạo quyền mới.
 */
return [

    'scopes' => ['own', 'branch', 'tenant'],

    'actions' => [
        'V' => 'view',
        'C' => 'create',
        'U' => 'update',
        'D' => 'delete',
        'A' => 'approve',
        'E' => 'export',
        'X' => 'cancel',
        'R' => 'restore',
    ],

    /** resource => [nhãn, các hành động được hỗ trợ] */
    'resources' => [
        'tenant.settings' => ['Cấu hình doanh nghiệp', 'VU'],
        'tenant.branch' => ['Chi nhánh', 'VCUD'],
        'tenant.user' => ['Người dùng', 'VCUD'],
        'tenant.role' => ['Vai trò & phân quyền', 'VCUD'],
        'billing.subscription' => ['Gói dịch vụ & thanh toán', 'VCU'],
        'crm.customer' => ['Khách hàng', 'VCUDE'],
        'purchase.supplier' => ['Nhà cung cấp', 'VCUDE'],
        'catalog.product' => ['Sản phẩm', 'VCUDE'],
        'inventory.warehouse' => ['Kho', 'VCUD'],
        'inventory.movement' => ['Nhập/xuất kho', 'VCAE'],
        'inventory.adjustment' => ['Kiểm kê & điều chỉnh kho', 'VCA'],
        'purchase.order' => ['Mua hàng', 'VCUAXE'],
        'sales.order' => ['Đơn bán hàng', 'VCUAXE'],
        'sales.return' => ['Trả hàng & hoàn tiền', 'VCAX'],
        'finance.receivable' => ['Công nợ & thu tiền', 'VCAXE'],
        'finance.cashbook' => ['Thu chi & sổ quỹ', 'VCAXE'],
        'season.season' => ['Mùa vụ', 'VCUDE'],
        'delivery.delivery' => ['Giao hàng', 'VCUE'],
        'notification.message' => ['Thông báo Zalo/SMS', 'VCU'],
        'report.report' => ['Báo cáo', 'VE'],
        'audit.log' => ['Nhật ký hoạt động', 'V'],
        'sales.online_order' => ['Đơn đặt hàng online', 'VCUAX'],
        'einvoice.invoice' => ['Hóa đơn điện tử', 'VCXE'],
    ],

    /**
     * Vai trò hệ thống được tạo cho mỗi Tenant.
     * grants: resource => "scope:CHỮ_CÁI" hoặc danh sách nhiều đoạn "scope:CHỮ_CÁI".
     * 'all' => scope  : toàn bộ quyền ở phạm vi đó (Owner; luôn tự đồng bộ khi catalog thêm quyền mới).
     */
    'roles' => [
        'owner' => [
            'name' => 'Chủ doanh nghiệp',
            'description' => 'Toàn quyền trong doanh nghiệp, gồm gói dịch vụ và sao lưu/khôi phục.',
            'all' => 'tenant',
        ],

        'admin' => [
            'name' => 'Quản trị viên',
            'description' => 'Quản trị người dùng, cấu hình và nghiệp vụ; bao trùm mọi vai trò trừ Owner; không quản lý sao lưu/gói dịch vụ.',
            'grants' => [
                'tenant.settings' => 'tenant:VU',
                'tenant.branch' => 'tenant:VCU',
                'tenant.user' => 'tenant:VCU',
                'tenant.role' => 'tenant:VCU',
                'billing.subscription' => 'tenant:V',
                'crm.customer' => 'tenant:VCUDE',
                'purchase.supplier' => 'tenant:VCUDE',
                'catalog.product' => 'tenant:VCUDE',
                'inventory.warehouse' => 'tenant:VCUD',
                'inventory.movement' => 'tenant:VCAE',
                'inventory.adjustment' => 'tenant:VCA',
                'purchase.order' => 'tenant:VCUAXE',
                'sales.order' => 'tenant:VCUAXE',
                'sales.return' => 'tenant:VCAX',
                'finance.receivable' => 'tenant:VCAXE',
                'finance.cashbook' => 'tenant:VCAXE',
                'season.season' => 'tenant:VCUDE',
                'delivery.delivery' => 'tenant:VCUE',
                'notification.message' => 'tenant:VCU',
                'report.report' => 'tenant:VE',
                'audit.log' => 'tenant:V',
                'sales.online_order' => 'tenant:VCUAX',
                'einvoice.invoice' => 'tenant:VCXE',
            ],
        ],

        'branch_manager' => [
            'name' => 'Quản lý chi nhánh',
            'description' => 'Quản lý và duyệt nghiệp vụ trong các chi nhánh được gán.',
            'grants' => [
                'tenant.settings' => 'branch:V',
                'tenant.branch' => 'branch:V',
                'tenant.user' => 'branch:V',
                'tenant.role' => 'branch:V',
                'crm.customer' => 'branch:VCUE',
                'purchase.supplier' => 'branch:VCU',
                'catalog.product' => 'branch:VCU',
                'inventory.warehouse' => 'branch:VCU',
                'inventory.movement' => 'branch:VCAE',
                'inventory.adjustment' => 'branch:VCA',
                'purchase.order' => 'branch:VCUAX',
                'sales.order' => 'branch:VCUAXE',
                'sales.return' => 'branch:VCAX',
                'finance.receivable' => 'branch:VCAE',
                'finance.cashbook' => 'branch:VCE',
                'season.season' => 'branch:VCU',
                'delivery.delivery' => 'branch:VCUE',
                'notification.message' => 'branch:V',
                'report.report' => 'branch:VE',
                'audit.log' => 'branch:V',
                'sales.online_order' => 'branch:VCUAX',
                'einvoice.invoice' => 'branch:VCE',
            ],
        ],

        'accountant' => [
            'name' => 'Kế toán',
            'description' => 'Công nợ, thu chi, hóa đơn và báo cáo.',
            'grants' => [
                'crm.customer' => 'tenant:VE',
                'purchase.supplier' => 'tenant:VE',
                'catalog.product' => 'tenant:V',
                'inventory.warehouse' => 'tenant:V',
                'inventory.movement' => 'tenant:V',
                'purchase.order' => 'tenant:VE',
                'sales.order' => 'tenant:VE',
                'sales.return' => 'tenant:VCA',
                'finance.receivable' => 'tenant:VCAXE',
                'finance.cashbook' => 'tenant:VCAXE',
                'season.season' => 'tenant:V',
                'notification.message' => 'tenant:V',
                'report.report' => 'tenant:VE',
                'sales.online_order' => 'tenant:V',
                'einvoice.invoice' => 'tenant:VCXE',
            ],
        ],

        'warehouse' => [
            'name' => 'Nhân viên kho',
            'description' => 'Nhập/xuất, kiểm kê kho tại chi nhánh được gán.',
            'grants' => [
                'purchase.supplier' => 'branch:V',
                'catalog.product' => 'branch:V',
                'inventory.warehouse' => 'branch:V',
                'inventory.movement' => 'branch:VC',
                'inventory.adjustment' => 'branch:VC',
                'purchase.order' => 'branch:VC',
                'sales.order' => 'branch:V',
                'delivery.delivery' => 'branch:V',
                'report.report' => 'branch:V',
                'sales.online_order' => 'branch:V',
            ],
        ],

        'sales' => [
            'name' => 'Nhân viên bán hàng',
            'description' => 'Tạo đơn, chăm sóc khách hàng; chỉ sửa/hủy đơn do mình tạo.',
            'grants' => [
                'crm.customer' => 'branch:VCU',
                'catalog.product' => 'branch:V',
                'inventory.warehouse' => 'branch:V',
                'inventory.movement' => 'branch:V',
                'sales.order' => ['branch:VC', 'own:UX'],
                'sales.return' => 'branch:VC',
                'finance.receivable' => 'branch:V',
                'season.season' => 'branch:VCU',
                'delivery.delivery' => 'branch:VC',
                'report.report' => 'own:V',
                'sales.online_order' => 'branch:VUA',
                'einvoice.invoice' => 'branch:V',
            ],
        ],

        'delivery' => [
            'name' => 'Nhân viên giao hàng',
            'description' => 'Chỉ xem và cập nhật các đơn giao được phân công.',
            'grants' => [
                'crm.customer' => 'own:V',
                'sales.order' => 'own:V',
                'delivery.delivery' => 'own:VU',
            ],
        ],
    ],
];
