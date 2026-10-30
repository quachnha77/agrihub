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
- **Đã xác nhận (2026-10-30):** mỗi user thuộc đúng 1 Tenant; các Tenant hoàn toàn độc lập. `users.tenant_id` NOT NULL (trừ Super Admin).

## 2b. Hiện thực (đã triển khai)
- `App\Support\Tenancy\TenantContext` (scoped theo request/job) giữ `tenantId` / chế độ nền tảng và đồng bộ sang PostgreSQL bằng `set_config('app.tenant_id' | 'app.is_platform', …)`. Không có context => RLS trả 0 dòng (fail closed).
- Middleware `tenant.context` đặt context từ **user đã xác thực**, chạy sau xác thực và trước route-model-binding; luôn `clear()` ở cuối request. Từ chối user bị vô hiệu hóa / Tenant bị khóa kể cả khi phiên còn hiệu lực.
- `tenant.user` chặn Platform Admin khỏi endpoint dữ liệu Tenant (cần impersonation có audit — chưa làm).
- Trait `BelongsToTenant`: global scope + tự gán `tenant_id` từ context khi tạo (bỏ qua giá trị truyền vào); `tenant_id` không nằm trong `$fillable`.
- RLS bật + FORCE trên `tenants`, `branches`, `users`, `user_branches` (policy `tenant_isolation`, có `WITH CHECK`). Nhờ FORCE, chính owner của bảng cũng bị áp dụng RLS; superuser/BYPASSRLS thì không — ứng dụng KHÔNG được kết nối bằng các role này.
- `users` không có global scope vì xác thực phải tìm user trước khi biết Tenant: `TenantAwareUserProvider` tìm/cập nhật user ở chế độ nền tảng, sau đó context chuyển về Tenant của user.
- Ràng buộc DB: user thuộc đúng 1 Tenant, trừ Platform Admin (`tenant_id IS NULL` và `is_platform_admin`), kiểm bằng CHECK.
- Quy ước cho bảng Tenant-scoped mới: `tenant_id` NOT NULL + FK, unique theo `(tenant_id, …)`, thêm bảng vào RLS (migration mới), dùng `BelongsToTenant`; bảng pivot phải ghi `tenant_id`.

## 3. Phòng thủ nhiều lớp (thiết kế)
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
