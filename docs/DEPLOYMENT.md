# AgriHub – Triển khai

Trạng thái: thiết kế; chưa có Dockerfile/compose.

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
  - Cài client PostgreSQL **cùng phiên bản chính** với máy chủ (`pg_dump`, `pg_restore`) trên máy chạy queue worker/scheduler; nếu không nằm trong PATH thì đặt `BACKUP_PG_BIN`. Docker dev: image `docker/backend` đã thêm `postgresql16-client` (**chưa chạy thử** vì môi trường phát triển hiện không có Docker).
  - Tạo role `agrihub_backup` (`NOSUPERUSER CREATEDB BYPASSRLS` + `pg_read_all_data` + là thành viên role của ứng dụng) và đặt `BACKUP_DB_USERNAME` / `BACKUP_DB_PASSWORD` **chỉ cho queue worker và scheduler**, không cho tiến trình web. Docker dev: `docker/postgres/init-app-role.sh` tạo sẵn role này khi khởi tạo volume mới; volume cũ phải tạo tay.
  - Bật `BACKUP_PLATFORM_ENABLED=true` (mặc định đã bật khi `APP_ENV=production`). Scheduler bắt buộc chạy: 01:30 sao lưu, 03:30 dọn bản cũ, Chủ nhật 04:30 khôi phục thử.
  - **Sao chép `storage/app/backups/platform` sang nơi lưu độc lập** (nhà cung cấp/khu vực khác) bằng cron `rclone`/`rsync`/S3. Hệ thống chưa tự làm việc này; không có nó thì bản sao lưu cùng chung số phận với máy chủ.
  - Sau mỗi lần triển khai nên chạy `php artisan agrihub:backup:platform` rồi `php artisan agrihub:backup:verify` và xem kết quả trước khi coi bản mới là an toàn.

## Release
Zero/low-downtime: build → migrate (tương thích ngược) → chuyển traffic. Có kế hoạch rollback. Ghi CHANGELOG.

## Hosting
Chưa chọn (VPS Docker / cloud). *Chờ xác nhận* (xem IMPLEMENTATION_PLAN).
