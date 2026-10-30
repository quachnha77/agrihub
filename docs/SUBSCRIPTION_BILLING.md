# AgriHub – Subscription & Billing

## 1. Gói dịch vụ
Thuộc tính gói: giá/user/tháng, giá/user/năm (**giá năm rẻ hơn 12× giá tháng**, mức giảm cấu hình được), giới hạn user, chi nhánh, dung lượng lưu trữ, bật/tắt tính năng (feature flags), thời gian dùng thử.

## 2. Vòng đời Subscription
`trialing → active → past_due → expired(read_only) → cancelled`
- **Trial**: thời hạn cấu hình; không cần thanh toán.
- **Gia hạn**: tạo kỳ mới (`subscription_periods`) khi **giao dịch thanh toán được xác minh**.
- **Hết hạn**: hệ thống **chỉ thông báo**; nhân viên AgriHub chủ động liên hệ khách để gia hạn/hỗ trợ. Gia hạn do Super Admin ghi nhận (kèm chứng từ, có audit). Hành vi sau hết hạn (chỉ đọc hay vẫn dùng trong thời gian ân hạn) là **cấu hình nền tảng**; mặc định chỉ đọc. **Không tự xóa dữ liệu.** *Chờ xác nhận mặc định.*
- Đổi gói / thêm user: ghi `subscription_changes`; do nhân viên xử lý thủ công khi cần (không prorate tự động ở giai đoạn đầu).

## 3. Snapshot theo kỳ
Mỗi `subscription_period` lưu: `plan_id, unit_price, user_count (snapshot), amount, currency, period_start, period_end, status`. Thay đổi giá gói sau này không ảnh hưởng kỳ cũ.

## 4. Thanh toán
- `payment_transactions` trạng thái: `pending → verified | failed | refunded`.
- **Không tự đánh dấu đã thanh toán**: chỉ khi xác minh qua webhook ký chữ ký của cổng thanh toán, hoặc Super Admin xác nhận thủ công kèm chứng từ (có audit).
- Webhook idempotent (khóa theo mã giao dịch).
- Cổng thanh toán: **ngân hàng + MoMo** (cổng ngân hàng cụ thể, ví dụ VNPay, *chờ xác nhận*), dùng adapter chung với thanh toán đơn online. Webhook phải xác minh chữ ký.

## 5. Enforcement giới hạn
Middleware `EnforceSubscription` + kiểm tra khi tạo user/branch/upload: vượt giới hạn → từ chối rõ ràng (HTTP 402/403 với mã lỗi `plan_limit_exceeded`). Chế độ read-only chặn mọi method ghi (trừ thanh toán/gia hạn).

## 6. Nhắc gia hạn
Mặc định popup + thông báo (in-app, Zalo OA, SMS) ở T-15, T-7, T-3, T-1 và ngày hết hạn; cấu hình trong `platform_settings`. Scheduler hàng ngày tạo thông báo cho Tenant, đồng thời tạo **danh sách việc cần liên hệ** cho nhân viên AgriHub (tenant sắp/đã hết hạn).

## 7. Báo cáo Super Admin
Doanh thu theo kỳ/gói, MRR, tenant sắp hết hạn, churn.
