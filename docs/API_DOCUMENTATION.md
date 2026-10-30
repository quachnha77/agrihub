# AgriHub – Quy ước API

> Tài liệu này là quy ước chung; endpoint đã triển khai nằm ở mục 2, phần còn lại ở mục 3 là dự kiến (sẽ sinh OpenAPI từ code).

## 1. Chung
- Base: `/api/v1`. Thay đổi phá vỡ → `/api/v2`.
- JSON, UTF-8. Thời gian ISO 8601 UTC. Tiền là **chuỗi decimal** (`"1250000.00"`), không số float.
- Xác thực: Sanctum (cookie SPA + CSRF; bearer token cho client khác). Tenant lấy từ phiên, **không** nhận `tenant_id` từ client.
- Phân trang: `?page=&per_page=` (tối đa 100) → `{data, meta:{page,per_page,total}, links}`. Tìm kiếm `?q=`, lọc `?filter[field]=`, sắp xếp `?sort=-created_at`.
- Header: `Idempotency-Key` bắt buộc cho POST tạo giao dịch tiền/kho (chưa triển khai). `X-Request-Id` **đã triển khai**: client/proxy có thể gửi (8–64 ký tự `A-Za-z0-9._-`), nếu không server tự sinh; luôn có trong header phản hồi, trường `request_id` của lỗi, log ứng dụng và audit log.
- Lỗi theo RFC 7807: `{type,title,status,detail,code,errors{field:[msg]}}`.
- Mã trạng thái: 200/201/204, 401 chưa đăng nhập, 403 không đủ quyền/subscription, 404 (cũng dùng cho tài nguyên Tenant khác để tránh lộ tồn tại), 409 xung đột/idempotency, 422 validate, 429 rate limit.

## 2. Đã triển khai (Giai đoạn 1, bước 2–3)
Xác thực bằng cookie phiên (Sanctum stateful): gọi `GET /sanctum/csrf-cookie` rồi gửi header `X-XSRF-TOKEN` cùng cookie ở các request ghi. Request phải đến từ host trong `SANCTUM_STATEFUL_DOMAINS`.

| Method & path | Mô tả | Lỗi chính |
|---|---|---|
| `GET /health`, `GET /health/ready` | Liveness / readiness (kiểm tra DB) | 503 khi DB lỗi |
| `POST /auth/login` | `{email, password}` → user kèm tenant & branches | 401 `invalid_credentials`, 403 `account_disabled` / `tenant_suspended`, 429 `too_many_attempts`, 400 `stateful_request_required`, 422 |
| `POST /auth/logout` | Kết thúc phiên (204) | |
| `GET /auth/me` | Hồ sơ người dùng hiện tại | 401 |
| `GET /auth/sessions` | Danh sách thiết bị/phiên của chính mình (định danh là mã băm, không lộ session id) | |
| `DELETE /auth/sessions/{id}` | Đăng xuất một thiết bị khác | 404, 422 `cannot_revoke_current_session` |
| `DELETE /auth/sessions` | Đăng xuất mọi thiết bị khác | |
| `GET /auth/me` | (bổ sung) kèm `roles` và `permissions` (map `key → scope`) của người đăng nhập | |
| `GET/POST /branches`, `GET/PATCH/DELETE /branches/{id}` | Chi nhánh (`?q=`, `?per_page=`). Quyền `tenant.branch.*`; scope `branch` chỉ thấy chi nhánh được gán. Xóa mềm; 409 `branch_in_use` nếu còn user | 403 `forbidden`, 404 (Tenant khác), 422, 403 `tenant_context_required` (Platform Admin) |
| `GET /audit-logs` | Audit log của Tenant (quyền `audit.log.view`). Lọc `module, action, result, actor, branch, subject_type, subject_id, q, from, to`; cursor pagination (`per_page` ≤ 100, `links.next` giữ nguyên bộ lọc); thời gian UTC | 403, 422 |
| `GET /platform/audit-logs` | Platform Admin: log toàn nền tảng, thêm `tenant=<uuid>\|none`; mỗi dòng có `tenant` | 403 `forbidden` |
| `GET /permissions` | Catalog quyền theo resource + các scope (để dựng UI phân quyền) | 403 |
| `GET/POST /roles`, `GET/PUT/PATCH/DELETE /roles/{id}` | Vai trò của Tenant kèm danh sách `{key, scope}`. Body tạo/sửa: `{name, description?, permissions:[{key,scope}]}` | 403 `permission_exceeds_privileges`, 409 `role_in_use`, 404, 422 |
| `GET /users`, `GET /users/{id}` | Người dùng của Tenant (scope `tenant`/`branch`/`own`) kèm vai trò | 403, 404 |
| `PUT /users/{id}/roles` | `{role_ids:[uuid]}` – thay toàn bộ vai trò của user | 403 `role_exceeds_privileges`, 422 `last_owner` / `validation_failed`, 404 |

Giới hạn tốc độ đăng nhập: 5 lần/phút theo cặp email+IP và 20 lần/phút theo IP; khóa tài khoản 15 phút sau 5 lần sai liên tiếp (phản hồi 429 giống nhau, không lộ tài khoản có tồn tại).

## 3. Nhóm endpoint dự kiến
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

## 4. Quy tắc
- Mọi endpoint có Policy; response ẩn trường nhạy cảm.
- Hành động trạng thái dùng sub-resource: `POST /sales-orders/{id}/cancel` thay vì DELETE.
- Chứng từ đã chốt: không `PUT/DELETE`; dùng điều chỉnh/hủy/đảo.
- Rate limit theo IP + user; siết chặt cho `/auth/*`.
