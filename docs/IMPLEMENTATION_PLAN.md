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
8. Backup + Monitoring/health + cảnh báo (kèm kiểm thử restore).
9. Frontend shell: đăng nhập, layout, router, i18n, guard quyền.

**Giai đoạn 2 – Business Core** (thứ tự): Catalog → Customers/Suppliers → Inventory (sổ kho) → Purchase → Sales (+hóa đơn) → Receivables/Payments → Cashbook.

**Giai đoạn 3 – Advanced**: Seasons → Delivery → Customer Portal → Reports → Promotions → Zalo OA.

**Giai đoạn 4 – Expansion**: Bank/đối soát, Financing, SMS/Email, tối ưu hiệu năng, scaling.

Mỗi bước: phân tích → liệt kê file/DB/API → (xác nhận nếu rủi ro) → code nhỏ → test → review bảo mật → cập nhật docs → báo cáo.

## 3. Quyết định

**Đã xác nhận (2026-10-30):** repo `https://github.com/quachnha77/agrihub`; stack mục tiêu (Laravel + React/TS + PostgreSQL + Redis, monorepo); shared DB + `tenant_id` + RLS; mỗi user thuộc 1 Tenant, các Tenant độc lập.

**Còn chờ xác nhận** (các mục 5–10 bên dưới; mục 1–4 đã chốt):
1. **Tài khoản/repo GitHub** để lưu code (tên repo, public/private, tổ chức/cá nhân)? Máy chưa cài `gh`; có thể push bằng `git` + Personal Access Token/đăng nhập trình duyệt.
2. Xác nhận stack: Laravel (phiên bản 11/12), React+TS+Vite, PostgreSQL 16, Redis. Đồng ý monorepo?
3. Multi-tenant: shared DB + `tenant_id` + RLS (đề xuất) — đồng ý?
4. Một user thuộc một hay nhiều Tenant?
5. Cổng thanh toán subscription (VNPay/MoMo/chuyển khoản đối soát)?
6. Hosting production & kênh cảnh báo (email/Telegram/Zalo).
7. Chính sách hết hạn: grace period bao lâu; prorate khi đổi gói?
8. Có cho phép tồn kho âm theo Tenant không?
9. Hóa đơn: hóa đơn nội bộ hay phải tích hợp hóa đơn điện tử (NĐ 123/2020)?
10. Lộ trình dữ liệu cần import từ phần mềm cũ (nếu có)?

## 4. Rủi ro
- Rò dữ liệu chéo Tenant → giảm bằng scope + RLS + test tự động.
- Sai lệch kho/công nợ → sổ giao dịch bất biến + transaction + test bất biến.
- Phạm vi lớn → bám giai đoạn, không làm nhiều module cùng lúc.
- Tuân thủ: hóa đơn điện tử, dữ liệu cá nhân, chính sách Zalo.
