# AgriHub – RBAC & Ma trận quyền

> **Nguồn sự thật của ma trận là `backend/config/rbac.php`** (catalog quyền + quyền mặc định của 7 vai trò hệ thống); bảng bên dưới là bản tóm tắt thiết kế ban đầu. Test `RbacCatalogTest` kiểm tra cấu hình nhất quán.

## 0. Hiện thực (đã triển khai)
- **Bảng**: `permissions` (catalog toàn cục), `roles` / `role_permissions` / `user_roles` (theo Tenant, có RLS). Mỗi Tenant mới tự có đủ vai trò hệ thống (observer `Tenant::created` → `RoleProvisioner`).
- **Vai trò hệ thống** (`is_system`, không xóa được): `owner`, `admin`, `branch_manager`, `accountant`, `warehouse`, `sales`, `delivery`. **Owner luôn có mọi quyền ở phạm vi tenant, không sửa được**, và được tự đồng bộ khi catalog thêm quyền. Các vai trò còn lại do Tenant tùy chỉnh được; chạy `agrihub:sync-permissions` sau khi triển khai bản có quyền mới sẽ bổ sung vai trò còn thiếu nhưng **không ghi đè** tùy chỉnh. Vai trò `customer` (cổng khách hàng) dùng guard riêng, làm ở Giai đoạn 3.
- **Quyền hiệu lực** của user = hợp các vai trò, mỗi quyền lấy **scope rộng nhất** (`own < branch < tenant`). Mỗi quyền là một Gate ability (`Gate::allows('sales.order.create')`); `User::hasPermission($key, $minScope)`, `permissionScope($key)`.
- **Phạm vi**: `tenant` = toàn doanh nghiệp; `branch` = các chi nhánh được gán (`user_branches`); `own` = bản ghi do chính mình tạo/được giao (áp dụng khi module nghiệp vụ tương ứng được làm).
- **Policy** đã áp dụng: `BranchPolicy`, `RolePolicy`, `UserPolicy`, `ProductPolicy`, `CustomerPolicy`, `CustomerGroupPolicy`, `SupplierPolicy`, `WarehousePolicy`, `StockDocumentPolicy`. Module sau phải có Policy riêng và test ma trận.
- **Kiểm kê** (2026-10-31): dùng quyền `inventory.adjustment.*` ("Kiểm kê & điều chỉnh kho") theo chi nhánh của kho: xem đợt cần `view`, bắt đầu/đếm/hoàn tất/hủy cần `create`. Chủ/Admin: mọi chi nhánh; Quản lý chi nhánh và Thủ kho: chi nhánh của mình (thủ kho không có `approve`, nhưng kiểm kê không cần duyệt). Kế toán, Bán hàng, Giao hàng: 403. Giá trị chênh lệch chỉ hiện với người xem được giá vốn.
- **Kho** (2026-10-31): kho, phiếu kho và tồn đều thuộc một chi nhánh nên quyền hiểu theo chi nhánh (`tenant` = mọi chi nhánh, `branch` = chi nhánh được gán, `own` không cho gì). Quyền mới `inventory.warehouse.*` (VCUD: Owner/Admin `tenant:VCUD`, Quản lý chi nhánh `branch:VCU`, Kế toán `tenant:V`, Thủ kho/Bán hàng `branch:V`; Giao hàng không có): migration `2026_10_31_160000` cấp quyền này cho các vai trò hệ thống của doanh nghiệp đã có từ trước (`agrihub:sync-permissions` chỉ tự cấp cho Owner). `inventory.movement.view` xem tồn/phiếu/thẻ kho, `inventory.movement.create` lập phiếu nhập/xuất/tồn đầu kỳ, `inventory.adjustment.create` lập phiếu điều chỉnh (Thủ kho có cả hai ở phạm vi chi nhánh). **Giá vốn là số liệu nhạy cảm (lộ ra là lộ biên lợi nhuận)**: chỉ người có `inventory.movement.create` (làm kho, vì phải nhập giá) hoặc `report.report.view` từ phạm vi chi nhánh (kế toán, quản lý) thấy `avg_cost`, `unit_cost`, `value`, `stock_value`; Nhân viên bán hàng chỉ thấy số lượng — các trường này không có trong JSON, không chỉ bị ẩn trên giao diện.
- **Khách hàng và nhà cung cấp** (2026-10-31): khách dùng chung cả doanh nghiệp nhưng mỗi khách có **một chi nhánh phụ trách**, nên `crm.customer.*` hiểu theo chi nhánh đó: `tenant` = mọi khách, `branch` = khách thuộc các chi nhánh được gán (Owner/Admin: `tenant` đủ quyền; Quản lý chi nhánh: `branch` xem/thêm/sửa/xuất; Bán hàng: `branch` xem/thêm/sửa; Kế toán: `tenant` xem/xuất; Giao hàng: `own` xem, **chưa cho gì** cho tới khi có phân công giao hàng). Chuyển khách sang chi nhánh khác đòi quyền tạo ở chi nhánh đích. Nhóm khách (`CustomerGroupPolicy`): thêm theo `crm.customer.create`, sửa/xóa chỉ `tenant`. Nhà cung cấp không thuộc chi nhánh nào: xem/thêm từ phạm vi `branch`, **sửa/xóa chỉ `tenant`** (Quản lý chi nhánh và Bán hàng thêm được nhưng không sửa được; Kho chỉ xem).
- **Chống leo thang quyền** (bắt buộc, có test):
  1. Chỉ cấp được quyền nằm trong phạm vi quyền của chính mình (cả khóa lẫn scope) khi tạo/sửa vai trò → `403 permission_exceeds_privileges`.
  2. Chỉ gán hoặc gỡ được vai trò có quyền nằm trong phạm vi của mình → `403 role_exceeds_privileges` (vì vậy chỉ Owner gán/gỡ được Owner; Admin không tự nâng quyền).
  3. Không sửa/xóa được vai trò mạnh hơn mình; Owner bất biến; vai trò hệ thống không xóa được; vai trò còn người dùng không xóa được (409).
  4. Không thể gỡ vai trò Owner cuối cùng đang hoạt động → `422 last_owner`.
- Platform Admin không có quyền Tenant; quyền nền tảng (`platform.*`) làm ở bước Super Admin.
- **Phân cấp vai trò**: Owner ⊃ Admin ⊃ {Branch Manager, Accountant, Warehouse, Sales, Delivery} (test `test_the_role_hierarchy_...` bảo vệ quan hệ này). Nhờ đó Admin quản lý được mọi nhân viên; chỉ Owner mới có thêm sao lưu/khôi phục và đổi gói dịch vụ.
- **Quản lý người dùng**: người thao tác chỉ được tạo/gán/sửa/khóa/xóa/reset mật khẩu người dùng có quyền nằm trong phạm vi quyền của mình (`Catalog::covers`), không tự khóa/xóa/reset chính mình, và luôn còn ít nhất một Owner hoạt động. Người có phạm vi `branch` chỉ gán được chi nhánh của mình và không gỡ được chi nhánh ngoài phạm vi.
- **Platform Admin trong phiên hỗ trợ**: chỉ có các quyền `*.view` ở phạm vi Tenant được hỗ trợ (chỉ đọc), xem MULTI_TENANT_DESIGN.md mục 2c.
- **Audit**: mọi thay đổi vai trò/quyền/gán vai trò và mọi nỗ lực leo thang quyền đều được ghi audit log (xem MONITORING_LOGGING.md).
- **Chưa làm**: cache quyền giữa các request.

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
| Products (theo chi nhánh: phạm vi `branch` = các chi nhánh được gán; đơn vị tính/nhóm hàng/thương hiệu: thêm = quyền tạo, sửa/xóa = cấp doanh nghiệp) | VCUDE | VCUDE | VCU | V | V | V | – | – |
| Inventory (movement) | VCAE | VCAE | VCAE | V | VC | V | – | – |
| Stock adjust/count | VCA | VCA | VCA | – | VC | – | – | – |
| Purchase | VCUAXE | VCUAXE | VCUAX | VE | VC (nhập) | – | – | – |
| Sales order | VCUAXE | VCUAXE | VCUAXE | VE | V | VCUX (own/branch) | V | V (own) |
| Sales return/refund | VCAX | VCAX | VCAX | VCA | – | VC | – | – |
| Receivables/Payments | VCAXE | VCAXE | VCAE | VCAXE | – | V | – | V (own) |
| Cashbook | VCAXE | VCAXE | VCE | VCAXE | – | – | – | – |
| Seasons | VCUDE | VCUDE | VCU | V | – | VCU | – | V (own) |
| Delivery | VCUE | VCUE | VCUE | – | V | VC | VU (own) | V (own) |
| Zalo/Notification | VCU | VCU | V | V | – | – | – | – |
| Reports | VE | VE | VE (branch) | VE | V (kho) | V (own) | – | – |
| Backup/Restore | – (chỉ Super Admin, ngoài hệ thống phân quyền của doanh nghiệp; quyền `backup.*` đã gỡ 2026-10-31) | – | – | – | – | – | – | – |
| Online orders (xác nhận) | VCUAX | VCUAX | VCUAX | V | V | VUA | – | C (own) |
| E-invoice | VCXE | VCXE | VCE | VCXE | – | V | – | V (own) |
| Audit log | V | V | V (branch) | – | – | – | – | – |

Ma trận này là **điểm khởi đầu**; sẽ được chuyển thành seeder và kiểm thử, rồi hoàn thiện theo từng module.

## 4. Quy tắc
- Quyền nhạy cảm (điều chỉnh kho, hủy đơn đã chốt, duyệt hoàn tiền) cần quyền `approve` riêng và ghi audit.
- Không cho tự nâng quyền; chỉ Owner gán Owner/Admin.
- Customer portal dùng guard riêng, không dùng chung quyền nhân viên.
- Thay đổi vai trò/quyền → audit + vô hiệu cache quyền.
