# Thiết kế Multi-tenant & Branch

## 1. Mô hình
Shared database, shared schema. Mọi bảng dữ liệu nghiệp vụ có `tenant_id` (NOT NULL, FK). Bảng theo chi nhánh có thêm `branch_id`.

Phân loại bảng:
- **Global (platform)**: `plans`, `tenants`, `platform_settings`, … – chỉ Super Admin.
- **Tenant-scoped**: có `tenant_id`.
- **Branch-scoped**: có `tenant_id` + `branch_id` (kho, đơn bán, phiếu thu/chi, …).

## 2. Xác định Tenant
- Tenant lấy từ **người dùng đã xác thực** (`users.tenant_id`) hoặc membership; không đọc tenant từ body/query/header do client gửi.
- Super Admin muốn thao tác trong Tenant: dùng cơ chế "impersonation/context" tường minh, có audit log.
- Một user thuộc 1 Tenant (mặc định). *Chờ xác nhận:* user có thuộc nhiều Tenant không?

## 3. Phòng thủ nhiều lớp
1. **Application**: `TenantScope` (global scope) + trait `BelongsToTenant` tự gán `tenant_id` khi tạo; mọi query lọc theo tenant.
2. **Policy**: kiểm tra `resource.tenant_id == auth.tenant_id` và phạm vi Branch.
3. **Database**: PostgreSQL RLS – policy `tenant_id = current_setting('app.tenant_id')::bigint`; middleware đặt `SET LOCAL app.tenant_id` mỗi request/job. App dùng DB role không phải owner/superuser để RLS có hiệu lực.
4. **Queue/Job/Command**: job mang `tenant_id` và thiết lập context trước khi chạy.
5. **Storage**: đường dẫn `tenants/{tenant_id}/…`, truy cập qua signed URL/controller kiểm quyền.
6. **Cache key** có tiền tố tenant.

## 4. Unique & index
Ràng buộc unique là **theo tenant**: `UNIQUE (tenant_id, code)`. Index ưu tiên bắt đầu bằng `tenant_id`.

## 5. Phạm vi Branch
User có danh sách branch được phép (`user_branches`) và/hoặc "tất cả chi nhánh". Quyền kèm phạm vi dữ liệu: `own | branch | tenant`.

## 6. Kiểm thử bắt buộc
- Tenant A không đọc/sửa/xóa được bản ghi Tenant B qua mọi endpoint (test tự động quét route).
- Cố tình gửi `tenant_id` của Tenant khác trong payload → bị bỏ qua/từ chối.
- Job chạy nền không rò context giữa các tenant.
- RLS: truy vấn thô không đặt context trả 0 dòng.

## 7. Khôi phục theo Tenant
Do dùng shared schema, restore theo Tenant = export có lọc `tenant_id` từ bản backup khôi phục vào DB tạm rồi import có kiểm soát. Xem BACKUP_DISASTER_RECOVERY.md.
