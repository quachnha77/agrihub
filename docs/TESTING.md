# AgriHub – Chiến lược kiểm thử

Trạng thái: chưa có test (chưa có code).

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
