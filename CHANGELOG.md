# Changelog

Định dạng theo Keep a Changelog.

## [Unreleased]

### Added
- Sao lưu/khôi phục theo Tenant (bước 8a): bảng `backups`/`restore_runs`, file `.agbk` mã hóa + xác thực (libsodium), chạy nền; danh sách theo ngày giờ, tạo thủ công, Export (tải về), Import (tải lên, kiểm tra toàn bộ), Khôi phục có xác nhận 2 bước (mật khẩu + tên doanh nghiệp), tự tạo bản `pre_restore` để hoàn tác, Tenant bảo trì trong lúc khôi phục, rollback khi lỗi, `tenant_id` luôn ghi đè nên không ghi sang Tenant khác; cho Chủ doanh nghiệp và Super Admin; mọi bước có audit; test bảo vệ buộc module mới đăng ký bảng vào backup; 343 test. Danh sách bảng nghiệp vụ được sao lưu hiện trống cho đến khi có module Giai đoạn 2.
- Subscription & Billing: `plans` (Super Admin cấu hình), `subscriptions` (bản chụp giá/giới hạn, RLS) và sổ kỳ `subscription_periods`; Tenant mới tự dùng thử **7 ngày**; hết hạn thì **dừng sử dụng** (đăng nhập và API trả 402 `subscription_expired`), chỉ nhân viên AgriHub mở lại được (gia hạn hoặc `reopen` tạm thời có lý do + audit); giới hạn số user và chi nhánh; gia hạn thủ công (tháng/năm), đổi gói, giá thương lượng; hàng đợi việc cần liên hệ cho nhân viên AgriHub; `subscription_notice` trong `/auth/me`; chủ doanh nghiệp thấy sự kiện billing trong audit log; 307 test. **Giá gói là giá trị giả định chờ xác nhận.**
- Giai đoạn 0: khởi tạo repository và bộ tài liệu AgriHub (README, docs/*). Chưa có source code.
- Ghi nhận quyết định: 1 user thuộc 1 Tenant; xác nhận stack và mô hình multi-tenant.
- Chốt: không tồn kho âm, HĐĐT, cổng thanh toán ngân hàng/MoMo, kênh cảnh báo Zalo/SMS/in-app, UI backup/restore; thêm tính năng đặt hàng online.
- Chốt: thanh toán ngân hàng + MoMo; HĐĐT đa nhà cung cấp làm sau; giá năm rẻ hơn tháng, hết hạn chỉ thông báo; đơn online trừ kho khi xác nhận; backup/restore cho Super Admin và Tenant Owner; không import dữ liệu cũ.
- Platform Admin & onboarding: API quản lý Tenant (onboarding Tenant + chi nhánh + Owner trong một transaction, sửa, khóa/mở khóa kèm thu hồi phiên), quản lý người dùng toàn hệ thống, **phiên hỗ trợ chỉ đọc** (lý do bắt buộc, có hạn, ghi audit, Tenant thấy được); quản lý người dùng của Tenant (tạo/sửa/khóa/xóa mềm/gán chi nhánh/reset mật khẩu) với chống leo thang quyền; mật khẩu tạm một lần + bắt buộc đổi; `POST /auth/password`; chính sách mật khẩu; Admin bao trùm mọi vai trò trừ Owner; tham số route sai uuid trả 404; 271 test.
- Audit Log: bảng `audit_logs` append-only, phân vùng theo tháng, RLS; `AuditLogger` + trait `Auditable` + `Redactor`; sự kiện đăng nhập/khóa tài khoản/phiên/phân quyền/leo thang quyền; API `GET /audit-logs` và `/platform/audit-logs`; `X-Request-Id`; `TRUSTED_PROXIES`; lệnh `agrihub:audit-maintain` (retention mặc định không xóa); 174 test.
- RBAC: catalog quyền `config/rbac.php`, bảng permissions/roles/role_permissions/user_roles (RLS), 7 vai trò hệ thống tự tạo cho mỗi Tenant, vai trò tùy chỉnh, Policy chi nhánh/vai trò/người dùng, chống leo thang quyền, Branch CRUD, `agrihub:sync-permissions`; 102 test.
- Identity & Tenancy: bảng tenants/branches/user_branches, mở rộng users; đăng nhập cookie Sanctum, khóa tài khoản, rate limit, quản lý phiên; TenantContext + RLS PostgreSQL; lỗi API theo RFC 7807; lệnh `agrihub:create-platform-admin`; 41 test.
- Khung dự án: backend Laravel 13, frontend React/TS/Tailwind, endpoint `/api/v1/health` và `/api/v1/health/ready`, Docker compose dev, GitHub Actions CI.
