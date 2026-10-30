# AgriHub – Bảo mật

Trạng thái: chính sách thiết kế; chưa triển khai.

## Xác thực & phiên
- Mật khẩu: Argon2id (hoặc bcrypt cost ≥ 12), chính sách độ mạnh, kiểm tra mật khẩu bị lộ (tùy chọn).
- 2FA TOTP (bắt buộc cho Super Admin & Tenant Owner; tùy chọn người khác). Mã khôi phục băm.
- Quản lý session: danh sách thiết bị, đăng xuất từ xa, hết hạn theo idle/absolute; đổi mật khẩu thu hồi mọi session.
- Khóa tạm thời sau N lần sai (theo tài khoản + IP), thông báo không tiết lộ tài khoản có tồn tại.
- Rate limiting: `/auth/*` chặt; API chung theo user/IP.

### Mật khẩu & tài khoản do quản trị cấp (đã triển khai)
- Chính sách: tối thiểu 10 ký tự, có chữ và số, không nằm trong danh sách mật khẩu phổ biến, không chứa tên đăng nhập (email) khi đủ dài (≥ 4 ký tự). Platform Admin tạo bằng lệnh: tối thiểu 12 ký tự.
- **Mật khẩu tạm** (khi onboarding, tạo user, reset): sinh bằng CSPRNG (16 ký tự, bỏ ký tự dễ nhầm), **chỉ hiển thị một lần** trong response (`Cache-Control: no-store`), không lưu bản rõ, không bao giờ vào audit log. Tài khoản đó có `must_change_password`: mọi API (trừ `/auth/me`, `/auth/logout`, `/auth/password`) trả `403 password_change_required` cho đến khi tự đổi.
- Đổi mật khẩu: yêu cầu mật khẩu hiện tại, giới hạn 10 lần/phút, thu hồi **mọi phiên khác** và hủy mã phiên hiện tại. Reset/khóa/xóa người dùng cũng đăng xuất họ ngay.
- Phiên gắn với hash mật khẩu (middleware `AuthenticateSession` của Sanctum): đổi mật khẩu ở đâu thì phiên cũ ở mọi nơi khác đều vô hiệu, kể cả khi hàng phiên chưa bị xóa.
- Quy tắc chống chiếm quyền: không thao tác (sửa/khóa/xóa/reset mật khẩu/gán chi nhánh) trên người dùng có quyền vượt quá quyền của mình; không tự khóa/xóa/reset chính mình; luôn còn ít nhất một Owner hoạt động. Vi phạm được ghi audit `denied`.
- Email là định danh duy nhất toàn hệ thống (kể cả tài khoản đã xóa mềm) và không đổi qua API; thông báo trùng email cố ý chung chung ("Email này không khả dụng"). Lưu ý: người có quyền tạo user vẫn có thể suy ra một email đã tồn tại trong hệ thống — chấp nhận được vì mô hình "1 user = 1 Tenant".
- **Số điện thoại cũng là định danh đăng nhập**: số Việt Nam, lưu dạng chuẩn `0912345678` (chấp nhận khoảng trắng/dấu chấm/gạch ngang/+84 khi nhập), **duy nhất toàn hệ thống** (chỉ mục duy nhất của DB + rule kiểm tra ở chế độ nền tảng vì RLS che các Tenant khác; thông báo trùng cố ý chung chung, không lộ số thuộc Tenant nào). Đăng nhập bằng số trả đúng một thông báo cho sai mật khẩu và tài khoản không tồn tại; giới hạn tốc độ và khóa tài khoản tính theo định danh ĐÃ CHUẨN HÓA nên viết số theo kiểu khác không né được; nhật ký ghi số đã chuẩn hóa, không ghi mật khẩu.
- Giám sát hạ tầng: endpoint `GET /health/monitor` chỉ tồn tại khi đặt `MONITOR_TOKEN`, so khớp bằng `hash_equals`, chỉ nhận mã qua header (không qua URL), không trả chi tiết nội bộ, có giới hạn tốc độ. Trang Sức khỏe hệ thống chỉ Super Admin xem được và có kiểm tra tự động: role database của ứng dụng không được bỏ qua RLS; `APP_DEBUG` bật ở production là nghiêm trọng.
- Phiên hỗ trợ của Platform Admin: xem docs/MULTI_TENANT_DESIGN.md mục 2c.

### Sao lưu / khôi phục (đã triển khai)
- File sao lưu được **mã hóa và xác thực** (libsodium secretstream) trước khi nằm trên disk; chỉ file do hệ thống tạo ra và còn nguyên vẹn mới được nhận khi Import. Lưu trên disk riêng tư, không có URL công khai; tải xuống qua API có kiểm quyền, ghi audit, `no-store`.
- Chỉ Chủ doanh nghiệp (và Super Admin) có quyền; khôi phục cần nhập lại mật khẩu + gõ tên doanh nghiệp; có bản `pre_restore` để hoàn tác; Tenant ở chế độ bảo trì khi khôi phục.
- Khôi phục **không bao giờ ghi sang Tenant khác** (kiểm tra Tenant trong file, ghi đè `tenant_id`, chỉ bảng trong danh sách cho phép, RLS) và không đụng tài khoản/mật khẩu/phân quyền/audit log. File sao lưu theo Tenant vì vậy không chứa mật khẩu băm của nhân viên.
- Chống file độc hại/phình to: giới hạn dung lượng tải lên và sau giải nén, giới hạn độ dài dòng, từ chối giá trị lồng/kiểu lạ.

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
- Sao lưu toàn hệ thống (chứa dữ liệu mọi Tenant): dùng **tài khoản database riêng** (`BYPASSRLS`, không phải superuser, không dùng cho web); mã hóa + xác thực ngay từ đầu ra `pg_dump` (không có bản rõ ra đĩa); **không có khôi phục qua HTTP** (chỉ dòng lệnh trên máy chủ). **Tải file về** chỉ dành cho Super Admin và phải **nhập lại mật khẩu** để nhận liên kết ký, hết hạn sau 2 phút, dùng một lần, gắn với đúng người + đúng bản (mã dùng một lần bị xóa ngay khi dùng; thử sai/hết hạn đều bị từ chối và ghi audit `platform_download_denied`); file tải về vẫn được mã hóa nên **khóa mã hóa phải cất riêng khỏi file** (xem BACKUP_DISASTER_RECOVERY.md mục 8). Mật khẩu chỉ truyền qua biến môi trường của tiến trình con, lệnh chạy không qua shell; Tenant không thấy được (RLS + CHECK `tenant_id` NULL); khôi phục **không bao giờ ghi đè database đang chạy** và loại bỏ phiên/token/hàng đợi cũ; lỗi hiển thị cho Super Admin chỉ gồm một dòng chẩn đoán đã rút gọn, không có mật khẩu.
- Thông báo trong ứng dụng (`notices`): mỗi người chỉ đọc/sửa thông báo của chính mình (lọc theo `user_id`, thêm RLS theo Tenant; thông báo của Platform Admin có `tenant_id NULL` nên Tenant không thấy). Nội dung thông báo/email chỉ chứa trạng thái và lý do đã rút gọn, **không có dữ liệu khách hàng**. Lỗi kênh gửi (mail) không làm hỏng nghiệp vụ đang chạy.

## Vận hành
- Cập nhật phụ thuộc định kỳ (composer/npm audit, Dependabot), quét ảnh Docker.
- Tối thiểu đặc quyền: DB role ứng dụng không phải owner; tài khoản deploy tách riêng.
- Audit log cho hành động nhạy cảm và của Super Admin.
- Quy trình ứng phó sự cố và thông báo vi phạm dữ liệu cá nhân (tuân thủ Nghị định 13/2023/NĐ-CP về bảo vệ dữ liệu cá nhân).
- Vị trí giao hàng: chỉ thu thập khi có sự đồng ý rõ ràng.
