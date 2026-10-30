# AgriHub – Chiến lược kiểm thử

Trạng thái: 307 test (**thuê bao: dùng thử khi onboarding, trạng thái trial/active/reopened/expired, hết hạn thì chặn đăng nhập và API, mở lại tạm thời, giới hạn user/chi nhánh, gia hạn tháng/năm, đổi gói và bản chụp giá, hàng đợi liên hệ, banner nhắc, cô lập giữa Tenant**, health, đăng nhập/khóa tài khoản/rate limit, quản lý phiên, **cô lập Tenant ở 3 lớp: API, Eloquent scope, PostgreSQL RLS**, lệnh tạo Platform Admin, **RBAC: tính nhất quán của catalog, quyền hiệu lực, Policy chi nhánh/vai trò/người dùng, chống leo thang quyền**, **onboarding/khóa Tenant, quản lý người dùng, mật khẩu tạm + đổi mật khẩu, phiên hỗ trợ chỉ đọc**, **audit log: ghi đúng actor/Tenant/nội dung, che dữ liệu nhạy cảm, bất biến, phân vùng + retention, RLS, API xem log**). Lưu ý: khi một test chuyển giữa những người dùng khác mật khẩu, dùng `freshBrowser()` (xóa phiên + guard) vì Sanctum gắn phiên với hash mật khẩu từng người; test cần nhiều request nhạy cảm đặt `platform.rate_limits.sensitive` cao. Data provider của PHPUnit chạy trước khi app khởi động nên không dùng được `config()`. Toàn bộ chạy trên PostgreSQL (`phpunit.xml` mặc định `pgsql` / `agrihub_test`; thông tin kết nối lấy từ `.env` hoặc biến môi trường). Bắt buộc có PostgreSQL (cục bộ 16.13 hoặc service trong CI); không dùng SQLite vì RLS/CHECK là tính năng của PostgreSQL.

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
