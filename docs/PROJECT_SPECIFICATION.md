# AgriHub – Đặc tả sản phẩm

## 1. Tổng quan
AgriHub là SaaS thương mại cho doanh nghiệp/cửa hàng vật tư nông nghiệp (phân bón, thuốc BVTV, hạt giống, vật tư khác). Nhiều Tenant dùng chung nền tảng, dữ liệu độc lập hoàn toàn.

Thực thể tổ chức: **Platform Admin → Tenant → Branch → User**; **Customer** thuộc Tenant.

## 2. Phân hệ & phạm vi

| # | Phân hệ | Giai đoạn | Điểm chính |
|---|---|---|---|
| 5.1 | Platform Admin | 1 | Quản lý Tenant (tạo/khóa/mở), user toàn hệ thống, gói, subscription, doanh thu, thông báo, nhật ký, health, cấu hình |
| 5.2 | Multi-tenant & Branch | 1 | Tenant xác định từ phiên đăng nhập, không tin tenant_id từ client; test cross-tenant |
| 5.3 | User & RBAC | 1 | Vai trò: Super Admin, Tenant Owner, Tenant Admin, Branch Manager, Accountant, Warehouse, Sales, Delivery, Customer, custom. Quyền theo module/action/phạm vi dữ liệu |
| 5.4 | Khách hàng | 2 | Hồ sơ, phân loại, liên hệ, địa chỉ, hạn mức tín dụng, lịch sử mua/thanh toán, công nợ, mùa vụ |
| 5.5 | Sản phẩm | 2 | Danh mục, thương hiệu, NCC, đơn vị & quy đổi, giá, mã vạch, lô, NSX/HSD |
| 5.6 | Kho | 2 | Nhập/xuất/kiểm kê/điều chỉnh/điều chuyển, theo dõi lô & HSD, cảnh báo; **tồn kho chỉ thay đổi qua giao dịch kho**. Đã có: nhập/xuất/điều chỉnh/tồn đầu kỳ, **kiểm kê** (khóa kho, hoàn tất bằng một phiếu điều chỉnh, không cần duyệt), **chuyển kho** (một bước, cùng hoặc khác chi nhánh, ghép sản phẩm theo mã, giữ lô) |
| 5.7 | NCC & Mua hàng | 2 | Đơn mua, phiếu nhập, chi phí, công nợ NCC, thanh toán, đối chiếu. Đã có: nhà cung cấp, **phiếu nhập hàng** (hàng vào kho ngay, chi phí kèm theo vào giá vốn, ghi nợ + trả tiền nhiều lần, hủy lần trả nhầm). Chưa có: đơn mua, trả hàng nhà cung cấp, đối chiếu |
| 5.8 | Bán hàng | 2 | Đơn bán, chiết khấu, đa phương thức, trả một phần, ghi nợ, hóa đơn, hủy/trả hàng/hoàn tiền. Đã có (quy trình đơn giản, 2026-11-02): **phiếu bán hàng** một bước (hàng ra kho ngay, khách trả đủ hoặc ghi nợ, khách lẻ trả đủ, tự chọn lô gần hết hạn trước, bớt tiền cả phiếu, chặn bán chịu vượt hạn mức / nợ quá hạn), thu thêm tiền và hủy lần thu nhầm. Chưa có: trả hàng/hoàn tiền, hóa đơn, in phiếu |
| 5.9 | Công nợ KH | 2 | Theo hóa đơn, phân bổ thanh toán, tuổi nợ, đối chiếu, lãi (tùy cấu hình & thỏa thuận). Đã có: **màn Công nợ khách** (khách đang nợ, tuổi nợ 4 mức, thu một khoản cho nhiều phiếu — đến hạn sớm nhất trước). Chưa có: lãi quá hạn, nhắc nợ, xuất Excel, đối chiếu |
| 5.10 | Mùa vụ | 3 | Cây trồng, diện tích, thời gian, sản phẩm dùng, chi phí, công nợ |
| 5.11 | Giao hàng | 3 | Phân công, trạng thái, ảnh/chữ ký xác nhận, vị trí (có đồng ý) |
| 5.12 | Thu chi & sổ quỹ | 2 | Phiếu thu/chi, sổ quỹ, báo cáo |
| 5.13 | Cổng khách hàng | 3 | Khách chỉ xem dữ liệu của chính mình |
| 5.14 | Zalo OA | 3 | Cấu hình/token theo Tenant (backend), mẫu tin, lịch sử gửi, tuân thủ chính sách Zalo |
| 5.15 | Subscription & Billing | 1 | Gói, giá theo user, tháng/năm, giới hạn, dùng thử, gia hạn, snapshot kỳ |
| 5.16 | Bảo mật | 1 | Xem SECURITY.md |
| 5.17 | Backup & DR | 1 | Xem BACKUP_DISASTER_RECOVERY.md |
| 5.18 | Audit/Log/Monitoring | 1 | Xem MONITORING_LOGGING.md |
| 5.20 | Đặt hàng online | 3 | Khách đặt hàng qua cổng; chọn thanh toán ngay (ngân hàng/MoMo) hoặc ghi công nợ; cửa hàng gọi điện xác nhận. Xem mục 6 |
| 5.21 | Hóa đơn điện tử | 4 (làm sau) | Mỗi Tenant tự kết nối nhà cung cấp HĐĐT bất kỳ qua adapter. Xem mục 7 |
| 5.19 | Báo cáo | 3 | Doanh thu, lợi nhuận dự kiến, NXT, công nợ, thu chi, top KH/SP, theo nhân viên/chi nhánh; xuất Excel/PDF |

## 3. Nguyên tắc nghiệp vụ bất biến
1. Tồn kho không sửa trực tiếp; mọi thay đổi = giao dịch kho (người thực hiện, thời gian, lý do).
2. Giao dịch tài chính đã chốt không xóa; dùng điều chỉnh / hủy / hoàn trả có audit.
3. Tiền tệ dùng `NUMERIC` decimal, không float.
4. Chốt/hủy/trả hàng ảnh hưởng tồn kho & công nợ phải nằm trong **một transaction**.
5. Không tự đánh dấu đã thanh toán subscription khi chưa xác minh giao dịch.
6. Hết hạn subscription → chế độ chỉ đọc (cấu hình được); không tự xóa dữ liệu.
7. Popup nhắc gia hạn mặc định ở 15/7/3/1 ngày & ngày hết hạn (cấu hình được).
8. Lãi công nợ: chỉ khi có cấu hình + thỏa thuận; lưu công thức, kỳ tính, lịch sử; phân biệt giá bán tín dụng và lãi quá hạn.

## 4. Quyết định đã chốt (2026-10-30)
- **Không cho phép tồn kho âm** (kiểm tra trong transaction, CHECK `>= 0`).
- **Kênh cảnh báo/thông báo**: Zalo OA, SMS, thông báo trong web khi đăng nhập (in-app).
- **Thanh toán**: cổng ngân hàng + MoMo.
- **Hóa đơn điện tử**: hỗ trợ mọi nhà cung cấp, do từng Tenant tự cấu hình; **phát triển sau** (không chặn các giai đoạn đầu).
- **Giá subscription**: tính theo user, theo tháng hoặc năm; **thanh toán năm rẻ hơn** thanh toán tháng.
- **Hết hạn/gia hạn**: hệ thống chỉ **thông báo**; nhân viên AgriHub liên hệ khách để gia hạn/hỗ trợ (Super Admin gia hạn thủ công).
- **Backup/Restore**: **chỉ Super Admin** (đổi ngày 2026-10-31; trước đó từng chốt cho cả Chủ doanh nghiệp). Super Admin là nơi xử lý sự cố, sao lưu, tải về, nhập file và khôi phục dữ liệu của từng doanh nghiệp và của toàn hệ thống; doanh nghiệp (kể cả Chủ doanh nghiệp) không có chức năng, quyền hay API này.
- Đây là hệ thống mới, không cần import dữ liệu từ phần mềm cũ.
- **Backup/Restore**: có giao diện cho quản trị viên (xem BACKUP_DISASTER_RECOVERY.md).

## 5. Phi chức năng
Giao diện tiếng Việt mặc định (i18n sẵn sàng), responsive, phân trang theo máy chủ/tìm kiếm/lọc, trạng thái loading (skeleton đúng hình, không để trang giật)/empty/error, không có nút trang trí. **Đơn giản và dễ dùng cho người không rành công nghệ:** mỗi màn hình một hành động chính, điều hướng gọn (≤ 8 mục chính, phần còn lại thành tab), chữ và nút đủ lớn, dùng được đầy đủ trên điện thoại (bảng thành thẻ, không tràn ngang), biểu tượng luôn có chú giải/nhãn. **Không chuyển trang khi thêm/sửa/xem một bản ghi:** các việc đó mở thành popup ngay trên trang đang xem (cả từ Tổng quan); lưu xong popup tự đóng và có thông báo nổi; đóng dở thì hỏi "Bỏ thay đổi chưa lưu?"; ô bắt buộc có dấu * và chú thích ở đầu biểu mẫu; biểu mẫu chỉ hiện ô cần thiết trước (ô ít dùng thu gọn), dùng từ người trong nghề hiểu, bấm tên trong bảng để sửa.

## 6. Đặt hàng online (5.20)
Luồng: Khách chọn sản phẩm/chi nhánh → chọn hình thức thanh toán → tạo đơn `pending_confirmation` → cửa hàng **gọi điện số điện thoại khách để xác nhận** → `confirmed` → (giao hàng) → hoàn tất.

Hình thức thanh toán khi đặt:
1. **Thanh toán ngay** qua cổng ngân hàng hoặc MoMo. Đơn chỉ ghi "đã thanh toán" sau khi nhận IPN/webhook **có xác minh chữ ký**; không tin redirect từ trình duyệt.
2. **Ghi công nợ**: chỉ khách có tài khoản đã được cửa hàng duyệt và còn hạn mức tín dụng; vượt hạn mức → từ chối hoặc chờ duyệt.

Quy tắc:
- Số điện thoại bắt buộc, được kiểm tra định dạng; ghi nhận thời điểm và người gọi xác nhận (audit).
- Đơn chờ quá thời hạn cấu hình tự hết hạn; nếu đã thanh toán mà bị từ chối thì đi quy trình hoàn tiền (không tự động xóa).
- Tồn kho: **chỉ trừ kho khi cửa hàng xác nhận đơn** (đã chốt). Đơn chưa xác nhận không giữ chỗ, không ảnh hưởng tồn. Nếu lúc xác nhận không đủ hàng → không cho xác nhận (hoặc xác nhận một phần có sự đồng ý của khách); không bao giờ để tồn âm. Đơn đã thanh toán online mà bị từ chối/thiếu hàng → hoàn tiền.
- Chống spam/đặt trùng: rate limit, captcha, Idempotency-Key.
- Khách đặt qua trang cửa hàng của Tenant (`/shop/{tenant}`) hoặc cổng khách hàng; Tenant xác định từ cửa hàng được truy cập, các thao tác nội bộ vẫn lấy Tenant từ phiên đăng nhập.
- Thông báo xác nhận qua Zalo OA/SMS.

## 7. Hóa đơn điện tử (5.21)
Tuân thủ NĐ 123/2020/NĐ-CP, TT 78/2021/TT-BTC. Không tự ký/phát hành: tích hợp qua **adapter chung (interface `EInvoiceProvider`)** để hỗ trợ **mọi nhà cung cấp** (VNPT, Viettel, MISA, MatBao, …). **Mỗi Tenant tự chọn nhà cung cấp và nhập thông tin kết nối** (lưu mã hóa). Thêm nhà cung cấp mới = thêm một adapter, không đổi lõi. Phát triển ở giai đoạn sau; trước đó bán hàng dùng chứng từ/hóa đơn nội bộ. Lưu mã tra cứu, số/ký hiệu, trạng thái (nháp, đã phát hành, thay thế, điều chỉnh, hủy) và file XML/PDF. Hóa đơn đã phát hành không sửa; dùng thay thế/điều chỉnh.

## 8. Câu hỏi mở (cần chủ sản phẩm xác nhận)
Xem mục "Quyết định cần xác nhận" trong [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).
