# Changelog

Định dạng theo Keep a Changelog.

## [Unreleased]

### Added
- Giai đoạn 0: khởi tạo repository và bộ tài liệu AgriHub (README, docs/*). Chưa có source code.
- Ghi nhận quyết định: 1 user thuộc 1 Tenant; xác nhận stack và mô hình multi-tenant.
- Chốt: không tồn kho âm, HĐĐT, cổng thanh toán ngân hàng/MoMo, kênh cảnh báo Zalo/SMS/in-app, UI backup/restore; thêm tính năng đặt hàng online.
- Chốt: thanh toán ngân hàng + MoMo; HĐĐT đa nhà cung cấp làm sau; giá năm rẻ hơn tháng, hết hạn chỉ thông báo; đơn online trừ kho khi xác nhận; backup/restore cho Super Admin và Tenant Owner; không import dữ liệu cũ.
- Khung dự án: backend Laravel 13, frontend React/TS/Tailwind, endpoint `/api/v1/health` và `/api/v1/health/ready`, Docker compose dev, GitHub Actions CI.
