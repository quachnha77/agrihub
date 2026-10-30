# AgriHub – Tiến độ & Kế hoạch triển khai

Cập nhật: 2026-10-30 · Kết quả khảo sát: **repository trống** (không source, không git, không tài liệu trước đó).

## 1. Bảng tiến độ

| Phân hệ | Trạng thái |
|---|---|
| Hạ tầng repo (monorepo, Docker, CI) | Missing |
| Authentication / Session / 2FA | Missing |
| Multi-tenant / Branch | Missing |
| User & RBAC | Missing |
| Super Admin / Tenant onboarding | Missing |
| Subscription & Billing | Missing |
| Audit Log | Missing |
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

Completed: 1 · In Progress: 0 · Missing: toàn bộ source code.

## 2. Lộ trình theo thứ tự phụ thuộc

**Giai đoạn 1 – SaaS Foundation**
1. Scaffold monorepo + Docker dev + CI + `.env.example`.
2. Identity: User, Auth (Sanctum), session, lockout, rate limit.
3. Tenancy: Tenant, Branch, TenantScope + RLS, test cross-tenant (làm sớm vì mọi module phụ thuộc).
4. RBAC: roles/permissions/policies + seeder theo ma trận.
5. Audit Log (service dùng chung).
6. Platform Admin: quản lý Tenant, onboarding.
7. Subscription & Billing + enforcement read-only/giới hạn + nhắc gia hạn.
8. Backup (kèm UI quản trị import/export) + Monitoring/health + cảnh báo (kèm kiểm thử restore).
9. Frontend shell: đăng nhập, layout, router, i18n, guard quyền.

**Giai đoạn 2 – Business Core** (thứ tự): Catalog → Customers/Suppliers → Inventory (sổ kho) → Purchase → Sales → Hóa đơn điện tử → Receivables/Payments → Cashbook. Nền tảng Notification (in-app + SMS) làm sớm ở Giai đoạn 1 vì billing/cảnh báo dùng chung.

**Giai đoạn 3 – Advanced**: Seasons → Delivery → Customer Portal → Payment Gateway (ngân hàng/MoMo) → Đặt hàng online → Reports → Promotions → Zalo OA.

**Giai đoạn 4 – Expansion**: Bank/đối soát, Financing, SMS/Email, tối ưu hiệu năng, scaling.

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

**Còn chờ xác nhận:**
1. Cổng ngân hàng cụ thể: VNPay, hay cổng khác / chuyển khoản VietQR có đối soát?
2. Nhà cung cấp hóa đơn điện tử (VNPT, Viettel, MISA, MatBao…) và nhà cung cấp SMS (eSMS, SpeedSMS, Stringee…).
3. Hosting production.
4. Chính sách hết hạn subscription: grace period bao lâu; prorate khi đổi gói?
5. Đơn online chưa xác nhận có giữ chỗ tồn kho không (đề xuất: chỉ giữ khi cửa hàng xác nhận).
6. Backup/restore: "quản trị viên" là Super Admin (toàn hệ thống), Tenant Owner (dữ liệu riêng Tenant), hay cả hai? (đề xuất: cả hai, xem BACKUP_DISASTER_RECOVERY.md mục 6).
7. Import dữ liệu từ phần mềm cũ (nếu có)?

## 4. Rủi ro
- Rò dữ liệu chéo Tenant → giảm bằng scope + RLS + test tự động.
- Sai lệch kho/công nợ → sổ giao dịch bất biến + transaction + test bất biến.
- Phạm vi lớn → bám giai đoạn, không làm nhiều module cùng lúc.
- Tuân thủ: hóa đơn điện tử, dữ liệu cá nhân, chính sách Zalo.
