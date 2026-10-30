# AgriHub – Tiến độ & Kế hoạch triển khai

Cập nhật: 2026-10-30 · Kết quả khảo sát: **repository trống** (không source, không git, không tài liệu trước đó).

## 1. Bảng tiến độ

| Phân hệ | Trạng thái |
|---|---|
| Hạ tầng repo (monorepo, Docker, CI) | In Progress – đã có khung Laravel + React, health API, CI, Docker compose (Docker chưa chạy thử) |
| Authentication / Session | **Completed** (đăng nhập cookie Sanctum, khóa tài khoản, rate limit, quản lý/đăng xuất từ xa). 2FA: Missing |
| Multi-tenant / Branch | **Completed** (Tenant, Branch CRUD có phân quyền, context, RLS, test chéo Tenant) |
| User & RBAC | **Completed** (catalog quyền, 7 vai trò hệ thống, vai trò tùy chỉnh, Policy, chống leo thang quyền). Tạo/sửa user: Missing |
| Super Admin / Tenant onboarding | **Completed** phần quản lý Tenant & người dùng (onboarding, sửa, khóa/mở khóa, phiên hỗ trợ chỉ đọc, quản lý user toàn hệ thống). Còn lại ở Subscription: gói, doanh thu, thông báo toàn hệ thống, cấu hình nền tảng |
| Quản lý người dùng của Tenant | **Completed** (tạo/sửa/khóa/xóa mềm, gán vai trò + chi nhánh, mật khẩu tạm, đổi mật khẩu) |
| Subscription & Billing | **Completed** phần gói/thuê bao, giới hạn user & chi nhánh, hết hạn → chỉ đọc (sau ân hạn), gia hạn thủ công, hàng đợi liên hệ, banner nhắc. Còn lại: cổng thanh toán tự động, nhắc Zalo/SMS, báo cáo doanh thu, kiểm tra dung lượng. **Giá/ân hạn/dùng thử là giả định, chờ xác nhận** |
| Audit Log | **Completed** hạ tầng (append-only, phân vùng, RLS, API xem, retention); các module nghiệp vụ sẽ gắn sự kiện khi được làm |
| Backup / Monitoring | Missing |
| Customers, Suppliers, Products | Missing |
| Inventory, Purchase, Sales | Missing |
| Receivables / Payments / Cashbook | Missing |
| Seasons, Delivery, Customer Portal | Missing |
| Reports, Promotions, Zalo OA | Missing |
| Đặt hàng online + cổng thanh toán | Missing |
| Hóa đơn điện tử | Missing |
| Thông báo đa kênh (Zalo OA/SMS/in-app) | Missing |
| Backup/restore UI (import/export) | Missing |
| Tài liệu (16 file theo prompt) | **Completed** (bản khởi tạo; ADR/ERD ở mức khái niệm) |

Completed: tài liệu · In Progress: khung dự án · Missing: toàn bộ module nghiệp vụ.

## 2. Lộ trình theo thứ tự phụ thuộc

**Giai đoạn 1 – SaaS Foundation**
1. Scaffold monorepo + Docker dev + CI + `.env.example`.
2. ✅ Identity: User, Auth (Sanctum), session, lockout, rate limit.
3. ✅ Tenancy: Tenant, Branch, TenantScope + RLS, test cross-tenant.
4. ✅ RBAC: roles/permissions/policies + vai trò mặc định theo ma trận.
5. ✅ Audit Log (service dùng chung).
6. ✅ Platform Admin: quản lý Tenant, onboarding, quản lý người dùng, phiên hỗ trợ (impersonation chỉ đọc).
7. ✅ Subscription & Billing + enforcement read-only/giới hạn + nhắc gia hạn (thanh toán tự động và nhắc qua Zalo/SMS làm sau cùng với Notification/Payment Gateway).
8. Backup (kèm UI quản trị import/export) + Monitoring/health + cảnh báo (kèm kiểm thử restore).
9. Frontend shell: đăng nhập, layout, router, i18n, guard quyền.

**Giai đoạn 2 – Business Core** (thứ tự): Catalog → Customers/Suppliers → Inventory (sổ kho) → Purchase → Sales (chứng từ/hóa đơn nội bộ) → Receivables/Payments → Cashbook. Nền tảng Notification (in-app + SMS) làm sớm ở Giai đoạn 1 vì billing/cảnh báo dùng chung.

**Giai đoạn 3 – Advanced**: Seasons → Delivery → Customer Portal → Payment Gateway (ngân hàng/MoMo) → Đặt hàng online → Reports → Promotions → Zalo OA.

**Giai đoạn 4 – Expansion**: Hóa đơn điện tử (adapter đa nhà cung cấp, Tenant tự cấu hình), Bank/đối soát, Financing, SMS/Email, tối ưu hiệu năng, scaling.

Mỗi bước: phân tích → liệt kê file/DB/API → (xác nhận nếu rủi ro) → code nhỏ → test → review bảo mật → cập nhật docs → báo cáo.

## 3. Quyết định

**Đã xác nhận (2026-10-30):** repo `https://github.com/quachnha77/agrihub`; stack mục tiêu (Laravel + React/TS + PostgreSQL + Redis, monorepo); shared DB + `tenant_id` + RLS; mỗi user thuộc 1 Tenant, các Tenant độc lập.

**Đã xác nhận thêm (2026-10-30):**
- Thanh toán: cổng ngân hàng + MoMo; khi đặt hàng online khách chọn thanh toán ngay hoặc ghi công nợ; cửa hàng gọi điện xác nhận.
- Thêm tính năng **đặt hàng online** (5.20).
- Kênh cảnh báo: Zalo OA, SMS, thông báo in-app khi đăng nhập web.
- **Không cho phép tồn kho âm.**
- Hóa đơn điện tử (5.21).
- Backup/restore có giao diện cho quản trị viên: lưu theo ngày, import/export.

**Đã xác nhận (lượt 3, 2026-10-30):**
- Thanh toán qua **ngân hàng và MoMo**.
- HĐĐT: hỗ trợ tất cả nhà cung cấp, do từng Tenant cấu hình; **làm sau**.
- Giá theo user, tháng hoặc năm; **năm rẻ hơn**. Hết hạn chỉ thông báo, nhân viên liên hệ gia hạn.
- Đơn online **chỉ trừ kho khi được xác nhận**.
- Backup/restore: Super Admin và Tenant Owner.
- Hệ thống mới, **không import dữ liệu cũ**.

**Còn chờ xác nhận:**
1. Cổng ngân hàng cụ thể (VNPay, hay chuyển khoản VietQR + đối soát, hay cả hai) — cần khi làm Payment Gateway (Giai đoạn 3), chưa chặn.
2. Nhà cung cấp SMS (eSMS, SpeedSMS, Stringee…) — cần khi làm Notification (Giai đoạn 1).
3. Hosting production — cần trước khi lên staging/production.
4. **Đang dùng giả định, cần chốt:** sau khi hết hạn gói có **7 ngày ân hạn** (vẫn dùng đầy đủ), sau đó **chỉ đọc**; dùng thử **14 ngày / 5 user**. Đổi trong `backend/config/billing.php`.
5. **Đang dùng giả định, cần chốt:** giá gói (Tiêu chuẩn 150.000 và Nâng cao 250.000 đồng/user/tháng; giá năm = 10 tháng) – chỉnh được qua `/platform/plans`.

## 4. Rủi ro
- Rò dữ liệu chéo Tenant → giảm bằng scope + RLS + test tự động.
- Sai lệch kho/công nợ → sổ giao dịch bất biến + transaction + test bất biến.
- Phạm vi lớn → bám giai đoạn, không làm nhiều module cùng lúc.
- Tuân thủ: hóa đơn điện tử, dữ liệu cá nhân, chính sách Zalo.
