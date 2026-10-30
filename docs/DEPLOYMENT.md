# AgriHub – Triển khai

Trạng thái: có Dockerfile + `docker-compose.yml` cho **development** (được job `docker` trong CI kiểm chứng; máy dev của dự án không chạy được Docker). **Chưa có cấu hình production** (compose/Nginx TLS/secrets) — làm khi chốt hosting.

## Môi trường
| Môi trường | Mục đích | Dữ liệu |
|---|---|---|
| Development | Phát triển cục bộ (docker compose) | Seed giả |
| Staging | Kiểm thử trước release, giống production | Dữ liệu ẩn danh/giả |
| Production | Khách hàng thật | Thật, có backup |

Mỗi môi trường tách biệt DB, Redis, storage, secrets, domain. Không dùng chung credential.

## Container
`nginx` · `app` (php-fpm) · `queue` (worker) · `scheduler` · `postgres` · `redis` · `frontend` (build tĩnh phục vụ qua nginx) · (tùy chọn) `minio`.

## Cấu hình
Mọi cấu hình qua biến môi trường; cung cấp `.env.example` (không giá trị thật). Production lấy secrets từ secret manager/CI secrets.

## Pipeline CI/CD (đề xuất, GitHub Actions)
1. Lint + static analysis (Pint, PHPStan/Larastan, ESLint, tsc).
2. Test backend (Pest/PHPUnit với PostgreSQL thật) + frontend (Vitest) + kiểm thử cross-tenant.
3. Build image, quét lỗ hổng.
4. Deploy staging tự động; production cần duyệt thủ công.
5. Migration chạy có kiểm soát (backup trước; không sửa migration đã chạy; `down()` được kiểm thử trên staging).

## Yêu cầu bắt buộc về database
- Ứng dụng phải kết nối PostgreSQL bằng **role thường (NOSUPERUSER, NOBYPASSRLS)**; superuser bỏ qua Row-Level Security. Test `test_application_database_role_cannot_bypass_rls` bảo vệ điều này.
- Sau mỗi lần triển khai bản có thay đổi catalog quyền: chạy `php artisan migrate --force` rồi `php artisan agrihub:sync-permissions`.
- Đặt `TRUSTED_PROXIES` (IP của Nginx/load balancer) để audit log và rate limit lấy đúng IP client; để trống nếu không có proxy (mặc định không tin `X-Forwarded-For`).
- Scheduler (`php artisan schedule:work` hoặc cron `schedule:run` mỗi phút) **bắt buộc chạy** trên production: `agrihub:audit-maintain` tạo sẵn phân vùng audit log. Thiếu scheduler thì log vẫn ghi được (tự tạo phân vùng khi thiếu) nhưng không có retention.
- Chỉ đặt `AUDIT_PURGE_ENABLED=true` sau khi đã backup/lưu trữ audit log lâu dài; `AUDIT_RETENTION_MONTHS` tối thiểu 12.
- Tạo Platform Admin đầu tiên: `php artisan agrihub:create-platform-admin <email>`; sau đó đăng nhập và onboard doanh nghiệp đầu tiên qua `POST /api/v1/platform/tenants` (mật khẩu tạm của Owner chỉ hiện một lần: chuyển cho khách qua kênh an toàn).
- `SESSION_DRIVER=database` là **bắt buộc** (đăng xuất từ xa, khóa Tenant/người dùng và đổi mật khẩu đều thu hồi phiên bằng cách xóa hàng trong bảng `sessions`).
- **Queue worker bắt buộc chạy** (`php artisan queue:work`, có sẵn service `queue` trong docker-compose): sao lưu/khôi phục chạy bằng job nền; thiếu worker thì bản sao lưu đứng ở `pending`.
- Sao lưu: đặt `BACKUP_ENCRYPTION_KEY` (chuỗi ngẫu nhiên ≥ 32 ký tự) **và cất ở nơi tách khỏi file sao lưu**; mất khóa là không đọc lại được mọi bản sao lưu. Để trống thì dùng `APP_KEY` (khi đó đổi `APP_KEY` sẽ làm bản sao lưu cũ không dùng được). Thư mục `storage/app/backups` phải nằm trên volume bền vững và được sao chép offsite; PHP `upload_max_filesize`/`post_max_size` và `client_max_body_size` của Nginx phải ≥ `BACKUP_MAX_UPLOAD_MB`.
- **Sao lưu toàn hệ thống (8b)** — chi tiết ở `docs/BACKUP_DISASTER_RECOVERY.md` mục 8:
  - Cài client PostgreSQL **cùng phiên bản chính** với máy chủ (`pg_dump`, `pg_restore`) trên máy chạy queue worker/scheduler; nếu không nằm trong PATH thì đặt `BACKUP_PG_BIN`. Docker dev: image `docker/backend` đã thêm `postgresql16-client` (đã được CI dựng và chạy `pg_dump` thật trong container — job `docker`).
  - Tạo role `agrihub_backup` (`NOSUPERUSER CREATEDB BYPASSRLS` + `pg_read_all_data` + là thành viên role của ứng dụng) và đặt `BACKUP_DB_USERNAME` / `BACKUP_DB_PASSWORD` **chỉ cho queue worker và scheduler**, không cho tiến trình web. Docker dev: `docker/postgres/init-app-role.sh` tạo sẵn role này khi khởi tạo volume mới; volume cũ phải tạo tay.
  - Bật `BACKUP_PLATFORM_ENABLED=true` (mặc định đã bật khi `APP_ENV=production`). Scheduler bắt buộc chạy: 01:30 sao lưu, 03:30 dọn bản cũ, Chủ nhật 04:30 khôi phục thử.
  - **Sao chép `storage/app/backups/platform` sang nơi lưu độc lập** (nhà cung cấp/khu vực khác) bằng cron `rclone`/`rsync`/S3. Hệ thống chưa tự làm việc này; không có nó thì bản sao lưu cùng chung số phận với máy chủ.
  - Sau mỗi lần triển khai nên chạy `php artisan agrihub:backup:platform` rồi `php artisan agrihub:backup:verify` và xem kết quả trước khi coi bản mới là an toàn.
  - **Tải bản sao về lưu riêng** (Super Admin, `POST /platform/backups/{id}/download-link`, nhập lại mật khẩu): làm đều đặn (hệ thống nhắc sau 7 ngày) và **cất `BACKUP_ENCRYPTION_KEY` ở nơi khác với file** — thiếu khóa thì file vô dụng.
  - **Thông báo trạng thái sao lưu**: cần cấu hình `MAIL_*` thật (SMTP) để Super Admin nhận email cảnh báo; đặt `BACKUP_ALERT_EMAILS` là hộp thư **ngoài hệ thống**. Scheduler chạy thêm `agrihub:backup:check` (mỗi giờ) và `agrihub:notices:prune` (03:45). Chi tiết: `docs/BACKUP_DISASTER_RECOVERY.md` mục 9.
  - **Sao lưu tự động theo Tenant** (`BACKUP_TENANT_AUTO_ENABLED`, mặc định bật ở production; `BACKUP_TENANT_AT`, `BACKUP_TENANT_KEEP_DAYS`, `BACKUP_TENANT_MIN_KEEP`) cần queue worker chạy; chi tiết `docs/BACKUP_DISASTER_RECOVERY.md` mục 10. **Đặt `BACKUP_ENCRYPTION_KEY` riêng** (`php artisan agrihub:backup:key --generate`) và ghi lại dấu vân tay; ở production dùng chung `APP_KEY` sẽ bị cảnh báo.
  - **SMTP theo Tenant do Super Admin cấu hình sau** (chưa làm). Hiện chỉ có `MAIL_*` của nền tảng cho cảnh báo gửi Super Admin.
  - Giới hạn tải lên của Import sao lưu phải khớp ở cả ba lớp: `BACKUP_MAX_UPLOAD_MB` (mặc định 512), PHP (`upload_max_filesize`/`post_max_size`, xem `docker/backend/php-agrihub.ini`) và Nginx (`client_max_body_size`). Mặc định của PHP chỉ 2 MB và Nginx 1 MB nên **phải** đặt khi lên production.

## Release
Zero/low-downtime: build → migrate (tương thích ngược) → chuyển traffic. Có kế hoạch rollback. Ghi CHANGELOG.

## Hosting
Ứng viên: **iNet** hoặc **Hostinger** (người dùng cho biết 2026-10-30; chưa mua gói nào, chưa chốt). Điều kiện kỹ thuật của AgriHub:
- **Phải là VPS/Cloud Server (quyền root), không phải shared hosting**: cần PostgreSQL 16 (shared hosting thường chỉ có MySQL), tiến trình nền chạy liên tục (queue worker + scheduler), cài `pg_dump` cùng phiên bản chính, Nginx + TLS, và Docker (hoặc cài trực tiếp PHP 8.4). Hostinger có VPS (có mẫu Ubuntu sẵn Docker); iNet có VPS/Cloud Server — **cần xác nhận lại gói, cấu hình, giá và khả năng snapshot trên trang của nhà cung cấp trước khi mua**.
- Cấu hình khởi điểm *ước lượng* (chưa đo tải thật): 2 vCPU, 4 GB RAM, ≥ 80 GB SSD/NVMe, Ubuntu LTS. Cần đo lại sau khi có dữ liệu thật.
- **Bản sao lưu phải nằm ở nơi độc lập với VPS** (nhà cung cấp/khu vực khác, hoặc ít nhất tải về máy riêng). Snapshot của chính nhà cung cấp VPS là lớp bảo vệ bổ sung, **không thay** cho bản sao lưu của ứng dụng (cùng nhà cung cấp, cùng rủi ro).
- Trước khi đưa dữ liệu thật lên: diễn tập khôi phục thảm họa trên chính VPS đó (BACKUP_DISASTER_RECOVERY.md mục 8), cấu hình SMTP gửi email cảnh báo, đặt `TRUSTED_PROXIES`, TLS và `BACKUP_ENCRYPTION_KEY` riêng.
