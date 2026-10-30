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

## Release
Zero/low-downtime: build → migrate (tương thích ngược) → chuyển traffic. Có kế hoạch rollback. Ghi CHANGELOG.

## Hosting
Chưa chọn (VPS Docker / cloud). *Chờ xác nhận* (xem IMPLEMENTATION_PLAN).
