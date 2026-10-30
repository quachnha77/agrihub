# AgriHub – Audit Log, Error Log & Monitoring

Trạng thái: thiết kế; chưa triển khai.

## 1. Audit Log (nghiệp vụ)

### 1.0 Hiện thực (đã triển khai)
- **Bảng `audit_logs`** (PostgreSQL): *chỉ ghi thêm* (trigger chặn UPDATE/DELETE/TRUNCATE, kể cả với Platform Admin), **phân vùng theo tháng** (UTC, `audit_logs_yYYYYmMM`), **RLS theo Tenant** (hàng `tenant_id IS NULL` = sự kiện cấp nền tảng, chỉ Platform Admin thấy), **không có khóa ngoại** (log phải sống lâu hơn dữ liệu nó mô tả).
- **Trường**: thời gian (µs), actor (`user`/`anonymous`/`system` + id + nhãn "Tên <email>"), Tenant, chi nhánh, IP, User-Agent (API trả thêm `device` dạng "Chrome trên Windows"), `request_id`, module, action, đối tượng (loại/id công khai/nhãn), `result` (`success`/`failure`/`denied`), `reason`, `before`/`after` (jsonb), `metadata` (jsonb).
- **Ghi log**: `App\Services\Audit\AuditLogger::record(module, action, subject:, before:, after:, result:, reason:, metadata:, …)`. Gọi **trong cùng transaction** với nghiệp vụ (hủy nghiệp vụ thì log cũng hủy). Thiếu phân vùng của tháng thì tự tạo rồi ghi lại (không mất sự kiện). Actor, Tenant, IP, UA, request id tự lấy từ request; chạy ngoài HTTP (seeder, scheduler, job) ghi là `system`.
- **Tự động cho model**: thêm trait `Auditable` → ghi `created` / `updated` (chỉ các trường đổi, before/after) / `deleted` / `restored`. Đã áp dụng cho `Tenant`, `User`, `Branch`, `Role`. Thuộc tính `$hidden` luôn bị che; `created_at`, `updated_at`, `remember_token` không bao giờ vào log; model khai báo thêm `$auditIgnore` (User bỏ qua bộ đếm/dấu thời gian đăng nhập).
- **Sự kiện có ngữ nghĩa** (gọi tường minh, vì thay đổi qua pivot/query builder không tự bắt được): `auth.login`, `auth.login_failed` (+`reason`: `invalid_credentials` / `account_locked` / `account_disabled` / `tenant_suspended`), `auth.account_locked`, `auth.logout`, `auth.session_revoked`, `auth.sessions_revoked_others`, `rbac.role_permissions_changed` (before/after quyền), `rbac.user_roles_changed`, `rbac.privilege_escalation_blocked` (denied), `rbac.user_roles_change_blocked` (denied, last owner), `rbac.system_roles_provisioned`, `audit.partitions_dropped`; thêm ở bước quản trị: `tenant.onboarded`, `tenant.suspended` / `tenant.activated` (kèm lý do và số phiên bị thu hồi), `user.status_changed`, `user.password_reset`, `user.branches_changed`, `auth.password_changed` / `auth.password_change_failed`, `rbac.user_change_blocked` (denied: Owner cuối cùng), và `platform.support_session_started` / `support_session_ended` / `support_session_expired` / `support_request` (Tenant được hỗ trợ thấy các sự kiện này trong nhật ký của mình). Các thay đổi trạng thái có sự kiện riêng thì tắt sự kiện `updated` tự động để không ghi trùng.
- **Che dữ liệu nhạy cảm** (`Redactor`, lớp bảo vệ thứ hai — người gọi vẫn không được truyền bí mật vào log): mọi khóa chứa `password`, `secret`, `token`, `authorization`, `api_key`, `private_key`, `otp`, `recovery_code`, `cvv`, `card_number`, `credential` → `[REDACTED]`; chuỗi > 2000 ký tự bị cắt; độ sâu tối đa 6; ký tự NUL bị loại.
- **Xem log**: `GET /api/v1/audit-logs` (quyền `audit.log.view`; phạm vi `tenant` = mọi log của Tenant, `branch` = log gắn với chi nhánh của mình, `own` = hành động của mình) và `GET /api/v1/platform/audit-logs` (Platform Admin, lọc `tenant=<uuid>|none`). Lọc: `module`, `action`, `result`, `actor`, `branch`, `subject_type`, `subject_id`, `q`, `from`, `to` (UTC; ngày không kèm giờ ở `to` = hết ngày), phân trang cursor mới → cũ (`per_page` ≤ 100), link trang kế giữ nguyên bộ lọc. Việc xem log không sinh log.
- **IP thật của client**: chỉ lấy từ `X-Forwarded-For` khi `TRUSTED_PROXIES` được cấu hình (mặc định không tin proxy nào → client không giả được IP trong log). Mọi request có `X-Request-Id` (nhận từ proxy/client nếu hợp lệ, hoặc tự sinh) trong header phản hồi, log ứng dụng, audit log và thân lỗi.
- **Retention**: lệnh `agrihub:audit-maintain` (lên lịch hằng ngày 02:30) tạo sẵn phân vùng cho `partitions_ahead` (3) tháng tới. Việc **xóa phân vùng quá hạn mặc định TẮT** (`AUDIT_PURGE_ENABLED=false`); khi bật, chỉ DROP nguyên phân vùng cũ hơn `AUDIT_RETENTION_MONTHS` (mặc định 24, sàn an toàn 12) và ghi sự kiện `audit.partitions_dropped`. Chỉ bật sau khi đã có backup/lưu trữ lâu dài cho audit log (xem BACKUP_DISASTER_RECOVERY.md).
- **Chưa làm / hạn chế đã biết**: sự kiện bán hàng, kho, công nợ, thanh toán, subscription, Super Admin quản lý Tenant (sẽ gắn khi các module đó được làm; hạ tầng đã sẵn sàng); không ghi lần bị 403 thông thường (tránh bị làm ngập log) — chỉ ghi nỗ lực leo thang quyền; log trong transaction bị rollback sẽ mất theo (với sự kiện "thất bại" cần giữ lại thì ghi ngoài transaction); chưa có chuỗi băm chống sửa ở mức vật lý (hiện chặn bằng trigger/quyền DB); chưa xuất file (Excel/CSV) và chưa có cảnh báo theo mẫu hành vi.

### 1.1 Yêu cầu gốc
Append-only, ghi: actor, tenant, branch, IP, thiết bị (user agent), thời gian, module, action, đối tượng, before/after (khi phù hợp), kết quả thành công/thất bại.

Bắt buộc ghi: đăng nhập/đăng xuất/thất bại, bán hàng (tạo/chốt/hủy/trả), điều chỉnh kho, công nợ & thanh toán, phân quyền, subscription/billing, mọi hành động Super Admin (kể cả impersonation).

Ghi qua `AuditService` trong cùng transaction với nghiệp vụ; che dữ liệu nhạy cảm; partition theo tháng; retention cấu hình (mặc định ≥ 2 năm với dữ liệu tài chính).

## 2. Application/Error Log
Log JSON có cấu trúc (request_id, tenant_id, user_id, route, latency). Cấp độ chuẩn PSR-3. Tập trung về một nơi (Loki/ELK hoặc Sentry cho exception – *chờ xác nhận*). Không log mật khẩu, token, số thẻ.

## 3. Monitoring
| Nhóm | Chỉ số |
|---|---|
| Hạ tầng | CPU, RAM, Disk, network |
| Dịch vụ | PostgreSQL (kết nối, replication lag, slow query), Redis, Nginx/PHP-FPM |
| Ứng dụng | API latency (p50/p95/p99), tỉ lệ lỗi HTTP 4xx/5xx, queue depth/failed jobs, scheduler heartbeat |
| Vận hành | Backup gần nhất, dung lượng storage |
| Tích hợp | Zalo OA, cổng thanh toán (tỉ lệ lỗi/độ trễ) |

Trạng thái: `normal | warning | critical | down` theo ngưỡng cấu hình. Trang health cho Super Admin; endpoint `/health` (liveness) và `/health/ready` (readiness).

## 4. Cảnh báo
Kênh đã chốt: **Zalo OA, SMS, thông báo trong web khi đăng nhập (in-app)**; email là tùy chọn. Cảnh báo hệ thống gửi cho Super Admin; cảnh báo nghiệp vụ (nợ quá hạn, hết hạn gói, tồn thấp) gửi theo vai trò Tenant. Người nhận phải đồng ý nhận Zalo/SMS (opt-in); lưu lịch sử gửi và trạng thái.

Sự cố `critical/down` → gửi ngay (email + kênh chat), chống spam (gộp/cooldown), có runbook cho từng cảnh báo.

Công cụ đề xuất: Prometheus + Grafana + Alertmanager, Uptime check ngoài (*chờ xác nhận*).
