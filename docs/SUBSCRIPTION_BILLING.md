# AgriHub – Subscription & Billing

Trạng thái: **đã triển khai** phần gói dịch vụ, thuê bao, giới hạn, hết hạn/chỉ đọc, gia hạn thủ công, danh sách việc cần liên hệ và banner nhắc. **Chưa làm**: thanh toán tự động qua cổng (ngân hàng/MoMo), nhắc qua Zalo OA/SMS, báo cáo doanh thu (xem mục 7).

> ⚠️ **Các con số mặc định dưới đây là GIẢ ĐỊNH, chưa được chủ sản phẩm chốt** (giá, ngày dùng thử, ân hạn, giới hạn). Tất cả cấu hình được: giá/giới hạn gói qua API `/platform/plans`, còn dùng thử/ân hạn/mốc nhắc trong `backend/config/billing.php`.

## 1. Gói dịch vụ (`plans`, dùng chung toàn hệ thống)
Mỗi gói: `key` (cố định, duy nhất), tên, mô tả, **giá/user/tháng** và **giá/user/năm** (VND, số nguyên), giới hạn chi nhánh (`null` = không giới hạn), dung lượng lưu trữ (MB), `is_active`, `sort_order`.
- Giá năm do Super Admin đặt, mặc định bằng **10 tháng** (rẻ hơn 12×; tương đương giảm ~16,7%).
- Không có xóa gói: gói đã bán còn được thuê bao tham chiếu, chỉ **ngừng bán** (`is_active=false`). Không gán được gói đã ngừng bán cho thuê bao.
- Gói mặc định được tạo bởi migration (**giá giả định**):

| key | Tên | VND/user/tháng | VND/user/năm | Chi nhánh | Lưu trữ |
|---|---|---|---|---|---|
| `standard` | Tiêu chuẩn | 150.000 | 1.500.000 | 3 | 5 GB |
| `pro` | Nâng cao | 250.000 | 2.500.000 | 10 | 50 GB |

## 2. Thuê bao (`subscriptions`) – mỗi Tenant đúng một
Lưu **bản chụp** tại thời điểm cấu hình: `plan_id`, `billing_cycle` (`monthly|yearly`), `seats` (số user đã mua), `unit_price` (VND/user/kỳ), `max_branches`, `storage_mb`, `is_trial`, `period_start`, `period_end`. Đổi giá/giới hạn của gói sau này **không** đổi thuê bao đang có.

**Trạng thái được suy ra từ thời gian** (không lưu, nên không bao giờ lệch):

| Trạng thái | Điều kiện | Hành vi |
|---|---|---|
| `trial` | còn hạn, `is_trial` | dùng đầy đủ |
| `active` | còn hạn | dùng đầy đủ |
| `grace` | quá hạn ≤ `grace_days` (mặc định **7 ngày**) | **vẫn dùng đầy đủ**, banner nhắc |
| `expired` | quá ân hạn | **chỉ đọc**: mọi request ghi bị `402 subscription_expired`; vẫn xem được dữ liệu, đăng nhập/đăng xuất/đổi mật khẩu bình thường. **Không khóa, không xóa dữ liệu** |

- Tenant mới (onboarding) tự có thuê bao **dùng thử**: `trial_days` = 14, `trial_seats` = 5, gói mặc định `standard`.
- Tenant **chưa có thuê bao** (dữ liệu cũ/môi trường dev) không bị hạn chế.
- Việc chặn ghi nằm ở middleware `EnforceSubscription` (alias `subscription.writable`) áp cho nhóm route dữ liệu Tenant; các route `/auth/*` nằm ngoài nên luôn dùng được. Phiên hỗ trợ của Platform Admin vốn đã chỉ đọc nên không bị ảnh hưởng.
- Việc ghi nhận đổi gói / gia hạn **chỉ do Platform Admin** thực hiện; Tenant chỉ xem (`GET /subscription`, quyền `billing.subscription.view`, có ở Owner và Admin).

## 3. Giới hạn gói (enforcement)
- **Số user**: user `active` và chưa xóa chiếm một suất (`seats`). Tạo user mới hoặc **mở khóa** user khi đã đủ suất → `402 seat_limit_reached`. Khóa/xóa user làm trống suất.
- **Chi nhánh**: tạo chi nhánh vượt `max_branches` → `402 branch_limit_reached`.
- **Hạ cấu hình**: không giảm `seats` xuống dưới số user đang hoạt động, không đổi sang gói có `max_branches` thấp hơn số chi nhánh hiện có → `422 subscription_limit_exceeded`.
- **Dung lượng** (`storage_mb`): đã lưu trong thuê bao; sẽ kiểm tra khi có module upload file (chưa có).

## 4. Sổ kỳ (`subscription_periods`) – chỉ thêm, không sửa
Mỗi lần dùng thử / gia hạn / đổi gói thêm một dòng bản chụp: `kind` (`trial|renewal|change`), gói, chu kỳ, `seats`, `unit_price`, `amount`, `period_start/end`, `paid_at`, `payment_method` (`bank_transfer|momo|cash|other`), `payment_ref`, `note`, `recorded_by`. Thay đổi giá sau này không ảnh hưởng dòng cũ.

## 5. Gia hạn thủ công
Chưa có cổng thanh toán tự động. Nhân viên AgriHub liên hệ khách, nhận tiền (chuyển khoản/MoMo/tiền mặt) rồi ghi nhận bằng `POST /platform/tenants/{id}/subscription/renew`:
- `periods` (1..24, mặc định 1) kỳ theo chu kỳ hiện tại: tháng → +N tháng, năm → +12N tháng.
- Còn hạn → nối tiếp từ ngày hết hạn; đã hết hạn → tính từ hôm nay (không tính bù thời gian đã ngừng dùng).
- `amount` mặc định = `unit_price × seats × periods`; có thể nhập số khác (giảm giá/thu thiếu), ghi kèm `payment_method`, `payment_ref`, `note`.
- Mọi thao tác ghi audit (`billing.renewed`, `billing.plan_changed`, `billing.trial_started`, `billing.contacted`); các sự kiện này thuộc Tenant nên **chủ doanh nghiệp thấy trong audit log của mình**.
- Đổi gói / số user / chu kỳ / giá thương lượng (`PATCH …/subscription`) có hiệu lực ngay, **không đổi ngày hết hạn và không tính tiền** (không prorate tự động ở giai đoạn đầu; khoản tiền ghi khi gia hạn).

## 6. Nhắc gia hạn & việc cần liên hệ
- **Trong ứng dụng**: `GET /auth/me` trả `subscription_notice` cho người dùng Tenant – `null` khi còn xa hạn; có dữ liệu (`state`, `days_left`, `period_end`, `read_only`, `is_trial`) khi đang dùng thử, chạm mốc **15/7/3/1 ngày**, đang ân hạn hoặc đã hết hạn. Frontend dùng để hiện banner/popup khi đăng nhập.
- **Cho nhân viên AgriHub**: `GET /platform/subscriptions/renewals` liệt kê thuê bao sắp hết hạn (trong `within_days`, mặc định 15 ngày) và đã hết hạn, hạn gần nhất lên trước, kèm điện thoại/Chủ doanh nghiệp để gọi; lọc `contacted=0|1`. Doanh nghiệp đang bị khóa không nằm trong danh sách. `POST …/subscription/contact {note}` ghi nhận đã liên hệ (đánh dấu trong danh sách); gia hạn xong thì dấu liên hệ tự xóa.
- **Chưa làm**: gửi nhắc qua Zalo OA/SMS (chờ nền tảng Notification và chọn nhà cung cấp SMS).

## 7. Còn lại (chưa triển khai)
- Thanh toán tự động: cổng ngân hàng + MoMo, adapter chung với thanh toán đơn online; **webhook phải xác minh chữ ký, idempotent theo mã giao dịch**, không tự đánh dấu đã thanh toán khi chưa xác minh. Cổng ngân hàng cụ thể (VNPay…) *chờ xác nhận*.
- Hóa đơn SaaS (`invoices_saas`), `payment_transactions`.
- Báo cáo Super Admin: doanh thu theo kỳ/gói, MRR, churn.
- Feature flags theo gói; kiểm tra `storage_mb`.
