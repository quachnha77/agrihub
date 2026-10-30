# AgriHub – Subscription & Billing

## 1. Gói dịch vụ
Thuộc tính gói: giá/user/tháng, giá/user/năm, giới hạn user, chi nhánh, dung lượng lưu trữ, bật/tắt tính năng (feature flags), thời gian dùng thử.

## 2. Vòng đời Subscription
`trialing → active → past_due → expired(read_only) → cancelled`
- **Trial**: thời hạn cấu hình; không cần thanh toán.
- **Gia hạn**: tạo kỳ mới (`subscription_periods`) khi **giao dịch thanh toán được xác minh**.
- **Hết hạn**: chuyển chế độ **chỉ đọc** (cấu hình được: grace period, read-only, hoặc chặn). **Không tự xóa dữ liệu.**
- Đổi gói / thêm user: ghi `subscription_changes`, tính tỷ lệ theo ngày (prorate – *chờ xác nhận chính sách*).

## 3. Snapshot theo kỳ
Mỗi `subscription_period` lưu: `plan_id, unit_price, user_count (snapshot), amount, currency, period_start, period_end, status`. Thay đổi giá gói sau này không ảnh hưởng kỳ cũ.

## 4. Thanh toán
- `payment_transactions` trạng thái: `pending → verified | failed | refunded`.
- **Không tự đánh dấu đã thanh toán**: chỉ khi xác minh qua webhook ký chữ ký của cổng thanh toán, hoặc Super Admin xác nhận thủ công kèm chứng từ (có audit).
- Webhook idempotent (khóa theo mã giao dịch).
- Cổng thanh toán cụ thể: *chờ xác nhận* (VNPay / MoMo / chuyển khoản + đối soát).

## 5. Enforcement giới hạn
Middleware `EnforceSubscription` + kiểm tra khi tạo user/branch/upload: vượt giới hạn → từ chối rõ ràng (HTTP 402/403 với mã lỗi `plan_limit_exceeded`). Chế độ read-only chặn mọi method ghi (trừ thanh toán/gia hạn).

## 6. Nhắc gia hạn
Mặc định popup + thông báo ở T-15, T-7, T-3, T-1 và ngày hết hạn; cấu hình trong `platform_settings`. Scheduler hàng ngày tạo thông báo.

## 7. Báo cáo Super Admin
Doanh thu theo kỳ/gói, MRR, tenant sắp hết hạn, churn.
