# AgriHub – Chiến lược kiểm thử

Trạng thái: 381 test (**sao lưu toàn hệ thống (`pg_dump`): bản mã hóa/checksum/không lộ bản rõ, lấy đủ dữ liệu mọi Tenant dù RLS FORCE, bỏ dữ liệu phiên/hàng đợi, từ chối tài khoản không có BYPASSRLS, lỗi `pg_dump`/kết nối không để lại file và không lộ mật khẩu, khôi phục thử vào database tạm + dọn sạch, phát hiện file bị sửa/giả mạo/mất, đối chiếu số dòng phát hiện lệch, khôi phục vào database mới đúng chủ sở hữu + RLS, từ chối ghi đè database đang chạy/tên xấu, không để lại database dở dang, khôi phục từ file khi mất bảng `backups`, retention ngày/tuần/tháng + bản thủ công + `min_keep`, bản bị gián đoạn, lệnh và lịch, API + cô lập Tenant, CHECK `tenant_id`**; mã hóa theo luồng đọc từng mẩu nhỏ; **sao lưu/khôi phục theo Tenant: mã hóa luồng (mọi biên khối, giả mạo, cắt bớt, sai khóa), sao lưu → khôi phục khớp từng byte kể cả id/JSON/số tiền, điểm quay lại `pre_restore`, xác nhận 2 bước, file hỏng/sai Tenant/độc hại, `tenant_id` trong file không thể chuyển dữ liệu sang Tenant khác, rollback khi lỗi giữa chừng, bảo trì Tenant, Export→Import→Restore, Super Admin, phân quyền, và test chặn bảng `tenant_id` chưa đăng ký backup**; **thuê bao: dùng thử khi onboarding, trạng thái trial/active/reopened/expired, hết hạn thì chặn đăng nhập và API, mở lại tạm thời, giới hạn user/chi nhánh, gia hạn tháng/năm, đổi gói và bản chụp giá, hàng đợi liên hệ, banner nhắc, cô lập giữa Tenant**, health, đăng nhập/khóa tài khoản/rate limit, quản lý phiên, **cô lập Tenant ở 3 lớp: API, Eloquent scope, PostgreSQL RLS**, lệnh tạo Platform Admin, **RBAC: tính nhất quán của catalog, quyền hiệu lực, Policy chi nhánh/vai trò/người dùng, chống leo thang quyền**, **onboarding/khóa Tenant, quản lý người dùng, mật khẩu tạm + đổi mật khẩu, phiên hỗ trợ chỉ đọc**, **audit log: ghi đúng actor/Tenant/nội dung, che dữ liệu nhạy cảm, bất biến, phân vùng + retention, RLS, API xem log**). Lưu ý: khi một test chuyển giữa những người dùng khác mật khẩu, dùng `freshBrowser()` (xóa phiên + guard) vì Sanctum gắn phiên với hash mật khẩu từng người; test cần nhiều request nhạy cảm đặt `platform.rate_limits.sensitive` cao. Data provider của PHPUnit chạy trước khi app khởi động nên không dùng được `config()`. Toàn bộ chạy trên PostgreSQL (`phpunit.xml` mặc định `pgsql` / `agrihub_test`; thông tin kết nối lấy từ `.env` hoặc biến môi trường). Bắt buộc có PostgreSQL (cục bộ 16.13 hoặc service trong CI); không dùng SQLite vì RLS/CHECK là tính năng của PostgreSQL.

## Các tầng
| Tầng | Công cụ đề xuất | Nội dung |
|---|---|---|
| Unit | Pest/PHPUnit, Vitest | Logic thuần: tính tiền, lãi, quy đổi đơn vị, phân bổ thanh toán |
| Feature/API | Pest + PostgreSQL thật | Endpoint, validation, policy, transaction |
| Security | Pest | Cross-tenant, IDOR, RBAC theo ma trận, rate limit, lockout |
| Concurrency | Pest | Hai đơn cùng xuất một lô; thanh toán trùng (idempotency) |
| Frontend | Vitest + Testing Library | Component, form, trạng thái loading/empty/error |
| E2E | Playwright | Luồng chính: đăng nhập → bán hàng → thu nợ |
| Restore | Script | Kiểm thử khôi phục backup |

## Bắt buộc
- Mỗi endpoint tenant-scoped có test truy cập chéo Tenant (tự động quét route để phát hiện thiếu).
- Ma trận RBAC sinh test từ RBAC_PERMISSION_MATRIX.
- Nghiệp vụ tiền/kho: test bất biến (tồn không âm khi không cho phép; Σ phân bổ = số tiền thu; chốt/hủy/trả hàng nhất quán kho + công nợ).
- Không tắt test hoặc hardcode dữ liệu để qua test.
- CI chặn merge khi test fail.

## Test sao lưu toàn hệ thống cần tài khoản sao lưu thật
`tests/Feature/Backup/PlatformBackupTest.php` tạo database nguồn riêng (đã commit — vì database test chính nằm trong transaction chưa commit mà `pg_dump` không nhìn thấy) và chạy `pg_dump`/`pg_restore` thật. Cần: client PostgreSQL cùng phiên bản chính (đặt `BACKUP_PG_BIN` nếu không có trong PATH) và role sao lưu — trên máy dev: `CREATE ROLE agrihub_backup LOGIN PASSWORD '…' NOSUPERUSER CREATEDB BYPASSRLS; GRANT pg_read_all_data TO agrihub_backup; GRANT <role_ứng_dụng> TO agrihub_backup;` rồi đặt `BACKUP_DB_USERNAME`/`BACKUP_DB_PASSWORD` trong `backend/.env`. **Chưa cấu hình thì các test này bị bỏ qua trên máy dev; trên CI (biến `CI`) thiếu cấu hình là LỖI** (CI tạo role này ở bước dựng database). Các test tự dọn database tạm; test khẳng định không còn database `agrihub_verify_*` sót lại.
