# AgriHub – RBAC & Ma trận quyền

## 1. Cấu trúc quyền
Quyền = `module.resource.action`, ví dụ `sales.order.create`, `inventory.stock.adjust`, `receivable.payment.approve`.

Action chuẩn: `view, create, update, delete, approve, export, cancel`.

Phạm vi dữ liệu (scope): `own` (do mình tạo) · `branch` (chi nhánh được gán) · `tenant` (toàn doanh nghiệp). Super Admin dùng `platform`.

Backend kiểm tra bằng Policy/Gate trên mọi endpoint; frontend chỉ ẩn/hiện UI.

## 2. Vai trò mặc định (hệ thống, không xóa; Tenant có thể tạo vai trò tùy chỉnh)

| Vai trò | Phạm vi | Mô tả |
|---|---|---|
| Super Admin | platform | Quản trị nền tảng; hành động đều audit |
| Tenant Owner | tenant | Toàn quyền Tenant, billing, phân quyền |
| Tenant Admin | tenant | Quản trị người dùng/cấu hình, không billing |
| Branch Manager | branch | Quản lý chi nhánh, duyệt |
| Accountant | tenant/branch | Công nợ, thu chi, báo cáo |
| Warehouse Staff | branch | Kho |
| Sales Staff | branch/own | Bán hàng, khách hàng |
| Delivery Staff | own | Giao hàng được phân công |
| Customer | own (portal) | Chỉ dữ liệu của chính mình |

## 3. Ma trận khởi điểm (V = view, C = create, U = update, D = delete, A = approve, E = export, X = cancel; – = không)

| Module | Owner | Admin | Branch Mgr | Accountant | Warehouse | Sales | Delivery | Customer |
|---|---|---|---|---|---|---|---|---|
| Tenant/Branch settings | VCUD | VCU | V | – | – | – | – | – |
| Users & Roles | VCUD | VCU | V | – | – | – | – | – |
| Subscription/Billing | VCU | V | – | – | – | – | – | – |
| Customers | VCUDE | VCUDE | VCUE | VE | – | VCU | V (được giao) | V (own) |
| Suppliers | VCUDE | VCUDE | VCU | VE | V | – | – | – |
| Products | VCUDE | VCUDE | VCU | V | V | V | – | – |
| Inventory (movement) | VE | VE | VCAE | V | VC | V | – | – |
| Stock adjust/count | VA | VA | VCA | – | VC | – | – | – |
| Purchase | VCUAXE | VCUAXE | VCUAX | VE | VC (nhập) | – | – | – |
| Sales order | VCUXE | VCUXE | VCUAXE | VE | V | VCUX (own/branch) | V | V (own) |
| Sales return/refund | VCAX | VCAX | VCAX | VCA | – | VC | – | – |
| Receivables/Payments | VCAXE | VCAXE | VCAE | VCAXE | – | V | – | V (own) |
| Cashbook | VCAXE | VCAXE | VCE | VCAXE | – | – | – | – |
| Seasons | VCUDE | VCUDE | VCU | V | – | VCU | – | V (own) |
| Delivery | VCUE | VCUE | VCUE | – | V | VC | VU (own) | V (own) |
| Zalo/Notification | VCU | VCU | V | V | – | – | – | – |
| Reports | VE | VE | VE (branch) | VE | V (kho) | V (own) | – | – |
| Backup/Restore | VCE+Restore | – | – | – | – | – | – | – |
| Online orders (xác nhận) | VCUAX | VCUAX | VCUAX | V | V | VUA | – | C (own) |
| E-invoice | VCXE | VCXE | VCE | VCXE | – | V | – | V (own) |
| Audit log | V | V | V (branch) | – | – | – | – | – |

Ma trận này là **điểm khởi đầu**; sẽ được chuyển thành seeder và kiểm thử, rồi hoàn thiện theo từng module.

## 4. Quy tắc
- Quyền nhạy cảm (điều chỉnh kho, hủy đơn đã chốt, duyệt hoàn tiền) cần quyền `approve` riêng và ghi audit.
- Không cho tự nâng quyền; chỉ Owner gán Owner/Admin.
- Customer portal dùng guard riêng, không dùng chung quyền nhân viên.
- Thay đổi vai trò/quyền → audit + vô hiệu cache quyền.
