# AgriHub – Audit Log, Error Log & Monitoring

Trạng thái: **Audit log đã triển khai; giám sát hạ tầng (8c) đã triển khai ở mức cơ bản** (mục 3a) — chưa có CPU/RAM, độ trễ API, tỉ lệ lỗi HTTP, Redis/Nginx, tích hợp ngoài; log tập trung chưa làm.

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

### 3a. Hiện thực giám sát hạ tầng (8c – đã triển khai)
**Nguồn duy nhất**: `App\Services\Monitoring\SystemHealth` đánh giá các thành phần (mỗi thành phần `ok | warning | critical`, thành phần tắt có chủ đích là `disabled` và không tính vào tình trạng chung — tình trạng chung là mức xấu nhất). Không gọi dịch vụ ngoài, không ném lỗi: thành phần không kiểm tra được thì "nghiêm trọng" với lý do ngắn (tên lớp lỗi, không lộ mật khẩu/địa chỉ).
| Thành phần | Kiểm tra | Ngưỡng mặc định (`config/monitoring.php`, đổi bằng `MONITOR_*`) |
|---|---|---|
| `database` | `select 1` (độ trễ), số kết nối so với `max_connections`, phiên bản | trễ > 500 ms cảnh báo; kết nối ≥ 80% cảnh báo, ≥ 95% nghiêm trọng; không kết nối được = nghiêm trọng |
| `rls` | role của ứng dụng **không** được là superuser/`BYPASSRLS` (nếu có, dữ liệu giữa các doanh nghiệp không còn được cô lập) | nghiêm trọng nếu sai |
| `queue` | việc chờ lâu nhất, số việc chờ, việc lỗi 24 giờ qua (trình điều khiển `database`); `sync` ở production là cảnh báo | chờ ≥ 10 phút cảnh báo, ≥ 30 phút nghiêm trọng; ≥ 200 việc chờ cảnh báo; lỗi ≥ 1 cảnh báo, ≥ 10 nghiêm trọng |
| `scheduler`, `queue_worker` | **tín hiệu sống** mỗi phút (`agrihub:monitor:beat` ghi `system_heartbeats`; việc `QueueHeartbeatJob` đi qua hàng đợi nên chứng minh cả chuỗi scheduler → hàng đợi → worker; việc này duy nhất nên worker chết không làm dồn ứ) | quá 5 phút cảnh báo, quá 15 phút nghiêm trọng; chưa từng có tín hiệu = nghiêm trọng ở production, cảnh báo ở nơi khác |
| `disk` | % dùng của đĩa chứa `storage` và đĩa sao lưu, ghi được `storage/app` | ≥ 80% cảnh báo, ≥ 90% nghiêm trọng |
| `config` | chỉ ở production: `APP_DEBUG` bật (nghiêm trọng), `APP_URL` không https, mail `log/array`, session không phải database/redis, queue `sync`, cache `array/file` (cảnh báo) | — |
| `backups` | trạng thái của `PlatformBackupMonitor` (mục 9 của BACKUP_DISASTER_RECOVERY) | — |

**Xem**: trang *Sức khỏe hệ thống* (`/platform/health`, tự làm mới 30 giây) và thẻ ở trang Tổng quan của Super Admin; API `GET /platform/health`.
**Cảnh báo chủ động** (`agrihub:monitor:check`, mỗi 5 phút, chỉ khi `MONITOR_ALERTS_ENABLED`, mặc định bật ở production): báo Super Admin (trong ứng dụng + email) **khi chuyển trạng thái** — xấu đi thì báo ngay; còn tồn tại thì nhắc lại (nghiêm trọng 6 giờ, cảnh báo 24 giờ); nghiêm trọng → cảnh báo không báo; trở lại bình thường thì báo "đã hồi phục". Trạng thái lưu ở `system_health_states`. Sao lưu có cảnh báo riêng nên không báo lại. **Khi chính database chết** (không lưu được trạng thái/thông báo): chỉ gửi email khẩn tới `BACKUP_ALERT_EMAILS` + `MONITOR_ALERT_EMAILS`, tối đa 30 phút một lần. Lệnh trả mã thoát 1 khi nghiêm trọng.
**Giám sát từ BÊN NGOÀI** (phần quan trọng nhất khi scheduler/queue/cả máy chủ cùng chết thì không ai tự báo được):
- *Dead-man's switch*: `agrihub:monitor:ping` (mỗi phút) gọi `MONITOR_PING_URL` (Healthchecks.io, Uptime Kuma "push"...) **chỉ khi hệ thống không nghiêm trọng**; dịch vụ ngoài báo khi mất tín hiệu. `MONITOR_PING_FAIL_URL` (tùy chọn) được gọi khi nghiêm trọng.
- *Hỏi chủ động*: `GET /api/v1/health/monitor` với `Authorization: Bearer <MONITOR_TOKEN>` (hoặc `X-Monitor-Token`) trả `{status, checked_at, failing}` (không lộ chi tiết), **503 khi nghiêm trọng** để Uptime Kuma/Zabbix báo động theo mã trạng thái. Không đặt `MONITOR_TOKEN` thì endpoint không tồn tại (404); không nhận mã qua query string (lọt vào log).
**Chưa làm**: CPU/RAM/mạng của máy chủ (cần agent như node_exporter), độ trễ API p50/p95/p99 và tỉ lệ lỗi 4xx/5xx, Redis, Nginx/PHP-FPM, replication lag/slow query, tích hợp Zalo OA/cổng thanh toán (chưa có module), tập trung log (Loki/Sentry), Prometheus/Grafana. Nên dùng một công cụ giám sát bên ngoài cho CPU/RAM và khả năng truy cập từ internet.


## 4. Cảnh báo
Kênh đã chốt: **Zalo OA, SMS, thông báo trong web khi đăng nhập (in-app)**; email là tùy chọn. Cảnh báo hệ thống gửi cho Super Admin; cảnh báo nghiệp vụ (nợ quá hạn, hết hạn gói, tồn thấp) gửi theo vai trò Tenant. Người nhận phải đồng ý nhận Zalo/SMS (opt-in); lưu lịch sử gửi và trạng thái.

Sự cố `critical/down` → gửi ngay (email + kênh chat), chống spam (gộp/cooldown), có runbook cho từng cảnh báo.

Công cụ đề xuất: Prometheus + Grafana + Alertmanager, Uptime check ngoài (*chờ xác nhận*).

**Đã triển khai – cảnh báo sao lưu** (chi tiết: `BACKUP_DISASTER_RECOVERY.md` mục 9): thông báo **trong ứng dụng** (bảng `notices`, `GET /notifications`) và **email** cho Super Admin khi sao lưu thất bại / bị gián đoạn / quá hạn, khôi phục thử không đạt, lâu chưa khôi phục thử, chưa tải bản sao ra ngoài; người dùng Tenant được báo khi sao lưu / khôi phục của họ xong hoặc thất bại. Chống spam bằng cửa sổ 24 giờ cho cảnh báo theo trạng thái. Lệnh `agrihub:backup:check` chạy mỗi giờ và trả mã thoát 1 khi nghiêm trọng. **Chưa có:** Zalo OA/SMS, giám sát từ bên ngoài (nếu scheduler/queue chết thì không ai gửi cảnh báo), cảnh báo hạ tầng (đĩa đầy, DB chậm) — thuộc 8c.
