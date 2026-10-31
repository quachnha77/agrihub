# AgriHub – Agricultural Business Management Platform

AgriHub là nền tảng SaaS đa doanh nghiệp (multi-tenant) quản lý kinh doanh vật tư nông nghiệp: khách hàng, sản phẩm, kho, mua hàng, bán hàng, công nợ, mùa vụ, giao hàng, thu chi, cổng khách hàng, Zalo OA, subscription/billing.

> Trạng thái: **Giai đoạn 1 – SaaS Foundation, bước 1 (khung dự án)**. Chưa có tính năng nghiệp vụ. Xem [IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) để biết tiến độ.

## Mô hình tổ chức

Platform (Super Admin) → Tenant (doanh nghiệp) → Branch (chi nhánh) → User (nhân viên) ; Customer (khách hàng của Tenant).

## Stack mục tiêu

| Lớp | Công nghệ |
|---|---|
| Frontend | React + TypeScript + Tailwind CSS |
| Backend | Laravel REST API (`/api/v1`) |
| Database | PostgreSQL |
| Kiến trúc | Modular Monolith |
| Triển khai | Docker + Nginx (dev / staging / production tách biệt) |
| File | Local private storage hoặc S3-compatible |

## Cấu trúc

```
backend/    Laravel 13 (PHP 8.4) – REST API /api/v1
frontend/   React 19 + TypeScript + Vite + Tailwind CSS 4
docker/     Dockerfile backend, cấu hình Nginx
docs/       Tài liệu
```

## Chạy môi trường dev

**Cách 1 – Docker** (Postgres 16, Redis, Nginx, queue, scheduler; *được job `docker` trong CI kiểm chứng; máy dev của dự án không chạy được Docker*):
```
cp .env.example .env          # đổi POSTGRES_PASSWORD
docker compose up -d --build
docker compose exec app cp .env.example .env
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```
API: http://localhost:8000/api/v1/health · Frontend: http://localhost:5173

**Cách 2 – Chạy trực tiếp** (cần PHP 8.4 với `pdo_pgsql`, Composer, Node 24, PostgreSQL 16):
> Không có Docker/quyền Administrator: dùng PostgreSQL bản zip ("binaries") của EDB, chạy `initdb` + `pg_ctl` trong thư mục người dùng, tạo role/database `agrihub` và `agrihub_test`. Test chạy trên PostgreSQL bằng cách đặt biến môi trường `DB_CONNECTION=pgsql`, `DB_DATABASE=agrihub_test`, … (phpunit.xml mặc định SQLite).
```
cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan serve
cd frontend && npm install && npm run dev
```
Sau khi `php artisan migrate && php artisan db:seed --class=DevSeeder`, mở http://localhost:5173 và đăng nhập (mật khẩu dev: `password`):
- `root@agrihub.test` – Super Admin (quản trị nền tảng): menu **Doanh nghiệp** (thêm doanh nghiệp, gia hạn/đổi gói, khóa, phiên hỗ trợ, sao lưu & khôi phục từng doanh nghiệp), **Việc cần liên hệ**, **Gói & giá**, sức khỏe và sao lưu hệ thống, thông báo. Hai doanh nghiệp demo có thuê bao còn hạn một năm (`php artisan db:seed --class=DevSeeder` tạo nếu chưa có, kèm 6 sản phẩm, 3 nhóm khách, 6 khách hàng và 4 nhà cung cấp mẫu mỗi doanh nghiệp để thử danh mục và khách hàng; số điện thoại, mã số thuế trong dữ liệu mẫu là dữ liệu giả).
- `owner@nong-phat.test`, `owner@vat-tu-xanh.test` – Chủ doanh nghiệp (hai Tenant demo độc lập); `admin@…`, `sales@…`, `kho@…` là các vai trò khác (menu **Sản phẩm**: Chủ doanh nghiệp/Admin thêm-sửa-xóa, Bán hàng/Kho chỉ xem; menu **Khách hàng**: Chủ doanh nghiệp/Admin thêm-sửa-xóa, Bán hàng thêm-sửa khách của chi nhánh mình; menu **Nhà cung cấp**: Chủ doanh nghiệp/Admin thêm-sửa-xóa, Kho chỉ xem, Bán hàng không thấy)
- Cũng đăng nhập được bằng **số điện thoại**: `0900000011` (owner Nông Phát), `0900000012` (admin Nông Phát), `0900000021` (owner Vật tư Xanh)... (dạng `0900 000 011` hay `+84 900 000 011` đều được). Số điện thoại chỉ cần duy nhất **trong một doanh nghiệp**: hai doanh nghiệp được dùng chung một số, đăng nhập bằng số tra theo subdomain (chưa bật subdomain thì số dùng chung không đăng nhập được, dùng email).
- **Quên mật khẩu / quên tài khoản**: liên kết ở trang đăng nhập. Chỉ gửi qua email của tài khoản; khi phát triển `MAIL_MAILER=log` nên thư nằm trong `backend/storage/logs/laravel.log` và cần chạy `php artisan queue:work` để thư được gửi.
- **Mỗi doanh nghiệp một subdomain** (tùy chọn): đặt `TENANT_BASE_DOMAIN=localhost` và `TENANT_URL_PORT=5173` trong `backend/.env`, rồi mở `http://nong-phat.localhost:5173` (Nông Phát), `http://vat-tu-xanh.localhost:5173` (Vật tư Xanh), `http://admin.localhost:5173` (Super Admin). Khi bật, mỗi người chỉ đăng nhập được ở đúng địa chỉ của mình.
- Super Admin tạo `root@agrihub.test` bằng `php artisan agrihub:create-platform-admin root@agrihub.test` nếu chưa có.
- `backend/.env` cần `SANCTUM_STATEFUL_DOMAINS` có `localhost:5173` (đã có sẵn trong `.env.example`).
```

## Kiểm thử
```
cd backend && php artisan test && ./vendor/bin/pint --test
cd frontend && npm run lint && npm test && npm run build
```
CI (GitHub Actions) chạy các lệnh trên với PostgreSQL thật.

## Tài liệu

| Tài liệu | Nội dung |
|---|---|
| [PROJECT_SPECIFICATION](docs/PROJECT_SPECIFICATION.md) | Đặc tả sản phẩm & phạm vi từng phân hệ |
| [SYSTEM_ARCHITECTURE](docs/SYSTEM_ARCHITECTURE.md) | Kiến trúc tổng thể, module, quyết định (ADR) |
| [MULTI_TENANT_DESIGN](docs/MULTI_TENANT_DESIGN.md) | Cô lập Tenant/Branch |
| [DATABASE_DESIGN](docs/DATABASE_DESIGN.md) | Quy ước & thiết kế database |
| [ERD](docs/ERD.md) | Sơ đồ quan hệ thực thể |
| [RBAC_PERMISSION_MATRIX](docs/RBAC_PERMISSION_MATRIX.md) | Vai trò & quyền |
| [SUBSCRIPTION_BILLING](docs/SUBSCRIPTION_BILLING.md) | Gói dịch vụ, thanh toán |
| [API_DOCUMENTATION](docs/API_DOCUMENTATION.md) | Quy ước API |
| [SECURITY](docs/SECURITY.md) | Bảo mật |
| [BACKUP_DISASTER_RECOVERY](docs/BACKUP_DISASTER_RECOVERY.md) | Backup & khôi phục |
| [MONITORING_LOGGING](docs/MONITORING_LOGGING.md) | Audit log, log, monitoring |
| [DEPLOYMENT](docs/DEPLOYMENT.md) | Môi trường & triển khai |
| [TESTING](docs/TESTING.md) | Chiến lược kiểm thử |
| [IMPLEMENTATION_PLAN](docs/IMPLEMENTATION_PLAN.md) | Tiến độ & lộ trình |
| [CHANGELOG](CHANGELOG.md) | Lịch sử thay đổi |

## Quy tắc Git

Không commit `.env`, secrets, dữ liệu thật. Tách commit theo module. Không force push / reset khi chưa được đồng ý.
