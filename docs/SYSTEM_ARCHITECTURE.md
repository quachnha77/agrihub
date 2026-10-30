# AgriHub – Kiến trúc hệ thống

> Hiện trạng: repository trống, nên đây là kiến trúc **đề xuất**, chưa có gì được triển khai.

## 1. Tổng thể

```
Browser (React SPA) ──HTTPS──> Nginx ──> PHP-FPM (Laravel /api/v1)
                                   │            ├── PostgreSQL
                                   │            ├── Redis (cache, queue, session, rate limit)
                                   │            ├── Queue worker / Scheduler
                                   │            └── Object storage (private)
                                   └──> static SPA assets
```

- **Modular Monolith**: một codebase Laravel, chia module theo bounded context; giao tiếp giữa module qua Service/Contract công khai, không truy cập trực tiếp bảng của module khác.
- Không Microservices ở giai đoạn đầu.

## 2. Cấu trúc repository đề xuất (monorepo)

```
/backend      Laravel (app/Modules/<Module>/{Domain,Http,Services,Models,Database})
/frontend     React + TS + Tailwind (src/modules/<module>)
/docker       Dockerfile, nginx, compose theo môi trường
/docs         Tài liệu
```

## 3. Module backend

| Nhóm | Module |
|---|---|
| Platform | Platform, Tenancy, Identity (Auth/User/Session), Access (RBAC), Subscription, Billing, Audit, Monitoring, Notification |
| Business | Customer, Supplier, Catalog (Product), Inventory, Purchase, Sales, Receivable, Payable, Cashbook, Season, Delivery, Report |
| Integration | Zalo, (sau) Bank, SMS/Email |

Phụ thuộc theo chiều: Identity/Tenancy → Access → Catalog/Customer/Supplier → Inventory → Purchase/Sales → Receivable/Payable/Cashbook → Report.

## 4. Quyết định kiến trúc (ADR tóm tắt)

| ADR | Quyết định | Lý do |
|---|---|---|
| 1 | Modular Monolith | Đội nhỏ, giao dịch tài chính cần ACID đơn giản |
| 2 | Shared database + `tenant_id` + PostgreSQL Row-Level Security (phòng thủ nhiều lớp) | Chi phí vận hành thấp; RLS chặn rò rỉ nếu lỗi code. Xem MULTI_TENANT_DESIGN |
| 3 | Auth: Laravel Sanctum (SPA cookie + token cho mobile/API) | Đơn giản, quản lý session/đăng xuất từ xa được. *Chờ xác nhận* |
| 4 | API REST có version `/api/v1`, JSON, lỗi theo RFC 7807 | Ổn định cho client |
| 5 | Queue/Scheduler bằng Redis + Laravel Horizon | Gửi Zalo, báo cáo, nhắc nợ, tính lãi, backup |
| 6 | Tiền = `NUMERIC(18,2)`, tính toán bằng chuỗi/BCMath | Tránh sai số float |
| 7 | Tồn kho = sổ giao dịch bất biến (`stock_movements`) + bảng số dư dẫn xuất | Truy vết, kiểm toán |
| 8 | Idempotency-Key cho API tạo giao dịch tiền/kho | Chống trùng |

## 5. Phân lớp trong module
`Controller (mỏng) → FormRequest (validate) → Service/Action (nghiệp vụ, transaction) → Repository (khi thực sự cần) → Model`. Policy kiểm quyền ở backend cho mọi action.

## 6. Frontend
React 19 + TypeScript + Vite + Tailwind 4. **Đã triển khai (bước 9):** khung ứng dụng đăng nhập bằng cookie phiên Sanctum (không lưu token ở localStorage; CSRF qua `/sanctum/csrf-cookie` + header `X-XSRF-TOKEN`), `react-router` (route + guard theo quyền, trang tải theo nhu cầu), TanStack Query (cache/refetch), `react-hook-form` + `zod` (kiểm tra dữ liệu; **mọi thông báo mặc định của zod đã được Việt hóa**, lỗi 422 của API cũng tiếng Việt và hiển thị đúng ô), `react-i18next` (chữ giao diện nằm ở `src/i18n/vi.ts`, thêm ngôn ngữ = thêm một file), chuông thông báo + trang Thông báo, banner gói dịch vụ/phiên hỗ trợ, lý do rõ ràng khi bị đưa ra khỏi phiên (hết phiên, gói hết hạn, doanh nghiệp bị khóa), trang Sao lưu hệ thống (Super Admin: tình trạng, danh sách, sao lưu ngay, tải về có nhập lại mật khẩu) và Sao lưu dữ liệu (Chủ doanh nghiệp: danh sách, tạo, tải về).

Cấu trúc `frontend/src`: `app/` (route, gốc), `features/<module>/` (api hooks + trang + component của module), `layout/`, `pages/`, `components/ui.tsx` (Button, TextField, Alert, Modal… dùng chung), `lib/` (api client, định dạng, form), `i18n/`. Module nghiệp vụ mới thêm thư mục trong `features/`, một route lazy trong `app/routes.tsx` dưới `<RequireAccess permission="…">` và mục menu trong `layout/AppLayout.tsx`. **Ẩn menu/trang theo quyền chỉ để dẫn đường; việc chặn thật nằm ở API (Policy + RLS).** Mọi màn hình có trạng thái đang tải, rỗng và lỗi; hỗ trợ bàn phím (liên kết bỏ qua menu, Esc đóng hộp thoại) và nhãn cho trình đọc màn hình.

## 7. Môi trường
Dev / Staging / Production tách biệt (DB, secrets, storage, domain). Xem DEPLOYMENT.md.
