# AgriHub – Bảo mật

Trạng thái: chính sách thiết kế; chưa triển khai.

## Xác thực & phiên
- Mật khẩu: Argon2id (hoặc bcrypt cost ≥ 12), chính sách độ mạnh, kiểm tra mật khẩu bị lộ (tùy chọn).
- 2FA TOTP (bắt buộc cho Super Admin & Tenant Owner; tùy chọn người khác). Mã khôi phục băm.
- Quản lý session: danh sách thiết bị, đăng xuất từ xa, hết hạn theo idle/absolute; đổi mật khẩu thu hồi mọi session.
- Khóa tạm thời sau N lần sai (theo tài khoản + IP), thông báo không tiết lộ tài khoản có tồn tại.
- Rate limiting: `/auth/*` chặt; API chung theo user/IP.

## Phân quyền & cô lập
- Kiểm tra quyền ở backend (Policy) cho mọi action; Tenant isolation theo MULTI_TENANT_DESIGN (scope + RLS).
- Phòng IDOR: tài nguyên khác Tenant trả 404.
- Customer portal guard riêng, chỉ dữ liệu của chính khách.

## Ứng dụng
- Validate đầu vào (FormRequest), query tham số hóa/Eloquent (chống SQLi), escape đầu ra & CSP (chống XSS), CSRF cho cookie SPA, CORS whitelist, security headers (HSTS, X-Content-Type-Options, frame-ancestors).
- Upload file: giới hạn loại/kích thước, quét MIME thật, đổi tên ngẫu nhiên, lưu **private**, truy cập qua signed URL ngắn hạn kiểm quyền.
- Mass-assignment: dùng `$fillable`/DTO; tenant_id không bao giờ fillable từ request.

## Secrets & dữ liệu
- Secrets trong biến môi trường / secret manager; không commit `.env`; không đặt API key ở frontend.
- Token Zalo/cổng thanh toán mã hóa tại cột (Laravel encrypted cast, khóa tách riêng) và chỉ dùng ở backend.
- Log không chứa mật khẩu/token/PII nhạy cảm (redaction).
- TLS 1.2+ mọi nơi; DB và backup mã hóa.

## Vận hành
- Cập nhật phụ thuộc định kỳ (composer/npm audit, Dependabot), quét ảnh Docker.
- Tối thiểu đặc quyền: DB role ứng dụng không phải owner; tài khoản deploy tách riêng.
- Audit log cho hành động nhạy cảm và của Super Admin.
- Quy trình ứng phó sự cố và thông báo vi phạm dữ liệu cá nhân (tuân thủ Nghị định 13/2023/NĐ-CP về bảo vệ dữ liệu cá nhân).
- Vị trí giao hàng: chỉ thu thập khi có sự đồng ý rõ ràng.
