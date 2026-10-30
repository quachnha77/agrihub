# AgriHub – RBAC & Ma trận quyền

> **Nguồn sự thật của ma trận là `backend/config/rbac.php`** (catalog quyền + quyền mặc định của 7 vai trò hệ thống); bảng bên dưới là bản tóm tắt thiết kế ban đầu. Test `RbacCatalogTest` kiểm tra cấu hình nhất quán.

## 0. Hiện thực (đã triển khai)
- **Bảng**: `permissions` (catalog toàn cục), `roles` / `role_permissions` / `user_roles` (theo Tenant, có RLS). Mỗi Tenant mới tự có đủ vai trò hệ thống (observer `Tenant::created` → `RoleProvisioner`).
- **Vai trò hệ thống** (`is_system`, không xóa được): `owner`, `admin`, `branch_manager`, `accountant`, `warehouse`, `sales`, `delivery`. **Owner luôn có mọi quyền ở phạm vi tenant, không sửa được**, và được tự đồng bộ khi catalog thêm quyền. Các vai trò còn lại do Tenant tùy chỉnh được; chạy `agrihub:sync-permissions` sau khi triển khai bản có quyền mới sẽ bổ sung vai trò còn thiếu nhưng **không ghi đè** tùy chỉnh. Vai trò `customer` (cổng khách hàng) dùng guard riêng, làm ở Giai đoạn 3.
- **Quyền hiệu lực** của user = hợp các vai trò, mỗi quyền lấy **scope rộng nhất** (`own < branch < tenant`). Mỗi quyền là một Gate ability (`Gate::allows('sales.order.create')`); `User::hasPermission($key, $minScope)`, `permissionScope($key)`.
- **Phạm vi**: `tenant` = toàn doanh nghiệp; `branch` = các chi nhánh được gán (`user_branches`); `own` = bản ghi do chính mình tạo/được giao (áp dụng khi module nghiệp vụ tương ứng được làm).
- **Policy** đã áp dụng: `BranchPolicy`, `RolePolicy`, `UserPolicy`. Module sau phải có Policy riêng và test ma trận.
- **Chống leo thang quyền** (bắt buộc, có test):
  1. Chỉ cấp được quyền nằm trong phạm vi quyền của chính mình (cả khóa lẫn scope) khi tạo/sửa vai trò → `403 permission_exceeds_privileges`.
  2. Chỉ gán hoặc gỡ được vai trò có quyền nằm trong phạm vi của mình → `403 role_exceeds_privileges` (vì vậy chỉ Owner gán/gỡ được Owner; Admin không tự nâng quyền).
  3. Không sửa/xóa được vai trò mạnh hơn mình; Owner bất biến; vai trò hệ thống không xóa được; vai trò còn người dùng không xóa được (409).
  4. Không thể gỡ vai trò Owner cuối cùng đang hoạt động → `422 last_owner`.
- Platform Admin không có quyền Tenant; quyền nền tảng (`platform.*`) làm ở bước Super Admin.
- **Chưa làm**: ghi audit log cho thay đổi phân quyền (bước 5 – bắt buộc), cache quyền giữa các request.

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
