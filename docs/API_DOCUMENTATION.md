# AgriHub – Quy ước API

> Chưa có endpoint nào được triển khai. Tài liệu này là quy ước; danh mục endpoint sẽ bổ sung theo module (dự kiến sinh OpenAPI từ code).

## 1. Chung
- Base: `/api/v1`. Thay đổi phá vỡ → `/api/v2`.
- JSON, UTF-8. Thời gian ISO 8601 UTC. Tiền là **chuỗi decimal** (`"1250000.00"`), không số float.
- Xác thực: Sanctum (cookie SPA + CSRF; bearer token cho client khác). Tenant lấy từ phiên, **không** nhận `tenant_id` từ client.
- Phân trang: `?page=&per_page=` (tối đa 100) → `{data, meta:{page,per_page,total}, links}`. Tìm kiếm `?q=`, lọc `?filter[field]=`, sắp xếp `?sort=-created_at`.
- Header: `Idempotency-Key` bắt buộc cho POST tạo giao dịch tiền/kho; `X-Request-Id` trả về mọi response.
- Lỗi theo RFC 7807: `{type,title,status,detail,code,errors{field:[msg]}}`.
- Mã trạng thái: 200/201/204, 401 chưa đăng nhập, 403 không đủ quyền/subscription, 404 (cũng dùng cho tài nguyên Tenant khác để tránh lộ tồn tại), 409 xung đột/idempotency, 422 validate, 429 rate limit.

## 2. Nhóm endpoint dự kiến
| Prefix | Mô tả | Giai đoạn |
|---|---|---|
| `/auth/*` | login, logout, 2FA, sessions, quên/đổi mật khẩu | 1 |
| `/platform/*` | Super Admin: tenants, plans, subscriptions, announcements, health | 1 |
| `/tenant/*`, `/branches`, `/users`, `/roles` | Quản trị Tenant | 1 |
| `/subscription/*` | Gói hiện tại, hóa đơn, gia hạn | 1 |
| `/audit-logs` | Tra cứu nhật ký | 1 |
| `/customers`, `/suppliers`, `/products`, `/categories`, `/brands`, `/units` | Danh mục | 2 |
| `/inventory/*` | movements, balances, counts, transfers, adjustments | 2 |
| `/purchase-orders`, `/goods-receipts` | Mua hàng | 2 |
| `/sales-orders`, `/sales-returns` | Bán hàng | 2 |
| `/receivables`, `/receipts`, `/payables` | Công nợ | 2 |
| `/cashbook`, `/cash-vouchers` | Thu chi | 2 |
| `/seasons`, `/deliveries` | Mùa vụ, giao hàng | 3 |
| `/portal/*` | Cổng khách hàng (guard riêng) | 3 |
| `/shop/{tenant}/*` | Đặt hàng online công khai (rate limit + captcha) | 3 |
| `/payments/webhooks/{gateway}` | IPN/webhook ngân hàng, MoMo: xác minh chữ ký, idempotent | 3 |
| `/einvoices/*` | Phát hành/tra cứu/thay thế HĐĐT (Tenant tự cấu hình nhà cung cấp) | 4 |
| `/backups/*` | Danh sách, tạo, export, import/restore (quyền quản trị) | 1 |
| `/notifications/*` | In-app, mẫu, lịch sử Zalo/SMS | 1 |
| `/reports/*`, `/exports/*` | Báo cáo, xuất file (job bất đồng bộ) | 3 |
| `/integrations/zalo/*` | Zalo OA | 3 |

## 3. Quy tắc
- Mọi endpoint có Policy; response ẩn trường nhạy cảm.
- Hành động trạng thái dùng sub-resource: `POST /sales-orders/{id}/cancel` thay vì DELETE.
- Chứng từ đã chốt: không `PUT/DELETE`; dùng điều chỉnh/hủy/đảo.
- Rate limit theo IP + user; siết chặt cho `/auth/*`.
