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
| Subscription & Billing | **Completed** phần gói/thuê bao, giới hạn user & chi nhánh, hết hạn → **dừng sử dụng, chỉ nhân viên AgriHub mở lại** (gia hạn hoặc reopen tạm thời), dùng thử 7 ngày, gia hạn thủ công, hàng đợi liên hệ, banner nhắc. Còn lại: cổng thanh toán tự động, nhắc Zalo/SMS, báo cáo doanh thu, kiểm tra dung lượng. **Giá gói là giả định, chờ xác nhận** |
| Audit Log | **Completed** hạ tầng (append-only, phân vùng, RLS, API xem, retention); các module nghiệp vụ sẽ gắn sự kiện khi được làm |
| Backup / Monitoring | **In Progress** – 8a **Completed**: sao lưu/khôi phục theo Tenant (mã hóa, Export/Import, điểm quay lại, xác nhận 2 bước, bảo trì, Owner + Super Admin). 8b **Completed**: sao lưu toàn hệ thống `pg_dump` (tài khoản riêng, mã hóa theo luồng, đối chiếu số dòng theo snapshot), lịch tự động, retention 30 ngày/12 tuần/12 tháng, khôi phục thử vào database tạm hằng tuần, khôi phục thảm họa bằng dòng lệnh vào database mới. **Còn thiếu:** sao chép offsite, WAL/PITR, diễn tập trên production. **Thông báo trạng thái sao lưu ✅** (trong ứng dụng + email cho Super Admin: thất bại, quá hạn, khôi phục thử không đạt, nhắc tải bản sao ra ngoài) và **Super Admin tải bản sao về lưu riêng ✅**. **8c ✅ giám sát hạ tầng cơ bản**: trang Sức khỏe hệ thống (database, RLS, hàng đợi, scheduler, queue worker, đĩa, cấu hình production, sao lưu), cảnh báo theo chuyển trạng thái, ping ra dịch vụ giám sát bên ngoài, endpoint cho công cụ giám sát. **Chưa có:** CPU/RAM, độ trễ/tỉ lệ lỗi API, Redis/Nginx, log tập trung; việc thuê và cấu hình dịch vụ giám sát bên ngoài |
| Customers, Suppliers, Products | Missing |
| Inventory, Purchase, Sales | Missing |
| Receivables / Payments / Cashbook | Missing |
| Seasons, Delivery, Customer Portal | Missing |
| Reports, Promotions, Zalo OA | Missing |
| Đặt hàng online + cổng thanh toán | Missing |
| Hóa đơn điện tử | Missing |
| Thông báo đa kênh (Zalo OA/SMS/in-app) | **In-app + email: nền tảng đã có** (bảng `notices`, `SystemAlert`, dùng cho cảnh báo sao lưu); Zalo OA/SMS: Missing |
| Backup/restore UI (import/export) | API Completed (8a). Giao diện: danh sách + tạo + tải về (Export) **Completed**; Import và Khôi phục (xác nhận 2 bước): chưa làm |
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
8. Backup + Monitoring, chia nhỏ: **8a ✅ sao lưu/khôi phục theo Tenant (API import/export)**; **8b ✅ sao lưu toàn hệ thống (`pg_dump`), lịch tự động, retention, kiểm thử restore**; **thông báo trạng thái sao lưu ✅ (in-app + email) và tải bản sao về lưu riêng ✅**; **8c ✅ giám sát hạ tầng cơ bản + ping/endpoint cho giám sát bên ngoài** (còn lại: CPU/RAM, độ trễ/tỉ lệ lỗi API, log tập trung).
9. ✅ Frontend shell: đăng nhập, layout, router, i18n (kể cả thông báo kiểm tra dữ liệu tiếng Việt), guard quyền, banner gói dịch vụ, lý do khi hết phiên, chuông thông báo, trang Sao lưu (Super Admin + Chủ doanh nghiệp). **Quản lý doanh nghiệp: giao diện Người dùng, Chi nhánh, Vai trò (chỉ xem) ✅.** **Còn lại:** Import và Khôi phục cho Chủ doanh nghiệp (API đã xong từ 8a); tạo/sửa vai trò tùy chỉnh; cấu hình doanh nghiệp.

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
3. Hosting production — **dự kiến iNet hoặc Hostinger** (chưa chọn/mua). Phải là VPS/Cloud Server có quyền root (cần PostgreSQL 16, queue worker, scheduler); shared hosting không đủ — xem `docs/DEPLOYMENT.md` mục Hosting. Cần chốt trước khi lên staging/production.
4. ✅ **Đã chốt:** hết hạn gói thì **không được dùng**, chỉ khi nhân viên AgriHub mở lại (gia hạn hoặc reopen); dùng thử **7 ngày** miễn phí.
5. ✅ **Giá gói: để TẠM (đã xác nhận 2026-10-30), Super Admin cấu hình sau** qua `/platform/plans` (hiện: Tiêu chuẩn 150.000 và Nâng cao 250.000 đồng/user/tháng; giá năm = 10 tháng; giới hạn 3/10 chi nhánh, 5/50 GB). Đổi giá không ảnh hưởng thuê bao đang có.
6. ✅ **SMTP (xác nhận 2026-10-30): Super Admin cấu hình SAU, riêng cho từng Tenant** (email gửi cho khách/nhân viên của doanh nghiệp đó). Chưa làm: bảng cấu hình SMTP theo Tenant (mật khẩu mã hóa), nút gửi thử, bộ gửi mail chọn cấu hình theo Tenant — làm cùng module Notification (Giai đoạn 1). Cảnh báo hệ thống gửi cho Super Admin vẫn dùng `MAIL_*` của nền tảng.
7. ✅ **Mỗi doanh nghiệp một subdomain (xác nhận 2026-10-31)**: `<slug>.<tên miền gốc>`, Super Admin ở `admin.<gốc>`. Đã làm: nhận diện Host, đăng nhập/phiên đúng địa chỉ, giao diện theo doanh nghiệp (docs/MULTI_TENANT_DESIGN.md 2d). **Cần chốt khi có hosting:** tên miền thật, DNS wildcard và chứng chỉ TLS wildcard (Hostinger/iNet cần hỗ trợ); chưa có tên miền riêng của khách và đổi slug. **Chưa có tên miền** (xác nhận 2026-10-31).
8. ✅ **Số điện thoại duy nhất theo doanh nghiệp** (vì đã có subdomain) và **quên mật khẩu / quên tài khoản** (2026-10-31): chỉ qua email của tài khoản, không lộ tài khoản có tồn tại hay không, liên kết một lần (docs/SECURITY.md). **Còn thiếu:** SMTP riêng cho từng doanh nghiệp (hiện dùng `MAIL_*` của nền tảng) và kênh SMS/Zalo để khôi phục bằng số điện thoại; người mất quyền vào email vẫn phải nhờ quản trị viên cấp mật khẩu tạm.
9. ✅ **Giao diện Super Admin** (2026-10-31): doanh nghiệp (danh sách, onboarding, chi tiết, khóa/mở khóa, phiên hỗ trợ), thuê bao (gia hạn, mở lại, đổi gói, ghi nhận liên hệ), việc cần liên hệ, gói & giá, hỗ trợ người dùng (mật khẩu tạm, khóa). **Còn thiếu:** thanh toán tự động (cổng ngân hàng/MoMo), nhật ký hoạt động của nền tảng trên giao diện, giao diện Import/Khôi phục sao lưu theo doanh nghiệp.

## 4. Rủi ro
- Rò dữ liệu chéo Tenant → giảm bằng scope + RLS + test tự động.
- Sai lệch kho/công nợ → sổ giao dịch bất biến + transaction + test bất biến.
- Phạm vi lớn → bám giai đoạn, không làm nhiều module cùng lúc.
- Tuân thủ: hóa đơn điện tử, dữ liệu cá nhân, chính sách Zalo.
