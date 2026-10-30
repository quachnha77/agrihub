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
| 5.6 | Kho | 2 | Nhập/xuất/kiểm kê/điều chỉnh/điều chuyển, theo dõi lô & HSD, cảnh báo; **tồn kho chỉ thay đổi qua giao dịch kho** |
| 5.7 | NCC & Mua hàng | 2 | Đơn mua, phiếu nhập, chi phí, công nợ NCC, thanh toán, đối chiếu |
| 5.8 | Bán hàng | 2 | Đơn bán, chiết khấu, đa phương thức, trả một phần, ghi nợ, hóa đơn, hủy/trả hàng/hoàn tiền |
| 5.9 | Công nợ KH | 2 | Theo hóa đơn, phân bổ thanh toán, tuổi nợ, đối chiếu, lãi (tùy cấu hình & thỏa thuận) |
| 5.10 | Mùa vụ | 3 | Cây trồng, diện tích, thời gian, sản phẩm dùng, chi phí, công nợ |
| 5.11 | Giao hàng | 3 | Phân công, trạng thái, ảnh/chữ ký xác nhận, vị trí (có đồng ý) |
| 5.12 | Thu chi & sổ quỹ | 2 | Phiếu thu/chi, sổ quỹ, báo cáo |
| 5.13 | Cổng khách hàng | 3 | Khách chỉ xem dữ liệu của chính mình |
| 5.14 | Zalo OA | 3 | Cấu hình/token theo Tenant (backend), mẫu tin, lịch sử gửi, tuân thủ chính sách Zalo |
| 5.15 | Subscription & Billing | 1 | Gói, giá theo user, tháng/năm, giới hạn, dùng thử, gia hạn, snapshot kỳ |
| 5.16 | Bảo mật | 1 | Xem SECURITY.md |
| 5.17 | Backup & DR | 1 | Xem BACKUP_DISASTER_RECOVERY.md |
| 5.18 | Audit/Log/Monitoring | 1 | Xem MONITORING_LOGGING.md |
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

## 4. Phi chức năng
Giao diện tiếng Việt mặc định (i18n sẵn sàng), responsive, phân trang/tìm kiếm/lọc, trạng thái loading/empty/error, không có nút trang trí.

## 5. Câu hỏi mở (cần chủ sản phẩm xác nhận)
Xem mục "Quyết định cần xác nhận" trong [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).
