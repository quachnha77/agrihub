# AgriHub – Thiết kế Database (PostgreSQL)

## 1. Quy ước
- Khóa chính: `bigint identity` (nội bộ) + `uuid` công khai cho API (*chờ xác nhận*).
- Mọi bảng: `created_at`, `updated_at`; `deleted_at` (soft delete) cho dữ liệu danh mục; **không** soft-delete để "xóa" dữ liệu tài chính đã chốt.
- Tiền: `NUMERIC(18,2)`; số lượng: `NUMERIC(18,3)`; tỷ lệ/lãi: `NUMERIC(9,6)`. Không dùng float.
- Tenant-scoped: `tenant_id` NOT NULL FK; unique theo `(tenant_id, …)`; index bắt đầu bằng `tenant_id`.
- Branch-scoped: thêm `branch_id`.
- Trạng thái dùng `varchar` + CHECK (hoặc enum PostgreSQL), có bảng lịch sử trạng thái cho chứng từ.
- Chứng từ đã chốt: bất biến; thay đổi qua chứng từ điều chỉnh/đảo (`reversal_of_id`).
- Giao dịch trùng: bảng `idempotency_keys (tenant_id, key, request_hash, response, created_at)`.
- Migration có `down()` có kiểm soát; không sửa migration đã chạy trên môi trường thật – tạo migration mới.
- Mỗi bảng mới phải đánh giá tenant isolation (cột + RLS policy).

## 2. Nhóm bảng theo module

| Module | Bảng chính |
|---|---|
| Platform | `plans`, `plan_features`, `tenants`, `platform_settings`, `announcements` |
| Identity/Access | `users`, `user_sessions`, `login_attempts`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_branches`, `two_factor_secrets` |
| Tenancy | `branches`, `tenant_settings` |
| Subscription/Billing | **Đã có:** `plans` (toàn cục, không `tenant_id`), `subscriptions` (1 dòng/Tenant, `tenant_id` UNIQUE, bản chụp giá/giới hạn, RLS), `subscription_periods` (sổ kỳ chỉ-thêm gồm dùng thử/gia hạn/đổi gói, RLS; tiền là `bigint` VND). **Chưa có:** `invoices_saas`, `payment_transactions` (trạng thái xác minh). Trạng thái thuê bao suy ra từ `period_end`, không lưu cột |
| Customer | `customer_groups`, `customers`, `customer_addresses`, `customer_contacts`, `customer_accounts` (portal) |
| Supplier | `suppliers`, `supplier_contacts` |
| Catalog | `categories`, `brands`, `units`, `products`, `product_unit_conversions`, `product_barcodes`, `product_images`, `product_prices`, `product_attributes` (JSONB cho đặc thù phân bón/thuốc BVTV/hạt giống) |
| Inventory | `warehouses`, `batches` (lô, NSX, HSD), `stock_movements` (sổ, bất biến), `stock_balances` (dẫn xuất), `stock_counts`, `stock_count_lines`, `stock_transfers`, `stock_transfer_lines`, `stock_adjustments` |
| Purchase | `purchase_orders`, `purchase_order_lines`, `goods_receipts`, `goods_receipt_lines`, `purchase_costs` |
| Sales | `sales_orders`, `sales_order_lines`, `sales_payments`, `sales_returns`, `sales_return_lines`, `refunds`, `document_revisions` |
| Receivable/Payable | `receivables` (theo hóa đơn), `receipt_allocations`, `payables`, `payable_allocations`, `interest_policies`, `interest_accruals` (công thức, kỳ, kết quả) |
| Cashbook | `cash_accounts`, `cash_vouchers` (thu/chi), `cashbook_entries` |
| Season | `crops`, `seasons`, `season_customers`, `season_orders`, `season_costs` |
| Delivery | `deliveries`, `delivery_assignments`, `delivery_status_history`, `delivery_proofs` (ảnh/chữ ký) |
| Notification/Zalo | `notification_templates`, `notifications`, `zalo_oa_configs` (token mã hóa), `zalo_customer_links`, `zalo_messages` |
| Audit/Monitoring | `audit_logs` (partition theo tháng), `system_health_checks`, `backup_runs`, `integration_events` |
| Online order | `online_orders` (hoặc `sales_orders.channel='online'`), `order_confirmations` (người gọi, thời điểm, ghi chú), `payment_intents`, `payment_events` (IPN thô, chữ ký đã xác minh, idempotent theo mã giao dịch) |
| E-invoice | `einvoice_providers` (cấu hình mã hóa theo Tenant), `einvoices` (số, ký hiệu, mã tra cứu, trạng thái, XML/PDF), `einvoice_events` |
| Backup | **Đã có:** `backups` (scope `tenant`/`platform`, loại `manual\|auto\|upload\|pre_restore`, trạng thái, đường dẫn trên disk riêng tư, kích thước, `checksum_sha256`, `format_version`, `schema_version`, `row_counts` jsonb, người tạo; RLS), `restore_runs` (bản nguồn, điểm quay lại `pre_backup_id`, người thực hiện, kết quả, lỗi; RLS), cột `tenants.restoring_at` (cờ bảo trì khi khôi phục). Bản **toàn hệ thống** (8b): `scope='platform'` và `tenant_id = NULL` (ràng buộc CHECK `backups_scope_tenant_check`: platform ⇔ tenant_id NULL; RLS khiến Tenant không thấy); thêm cột `verify_status` (`passed\|failed`), `verified_at`, `verify_error` ghi kết quả khôi phục thử; index `(scope, kind, status, created_at)`; cột `downloaded_at` (lần tải về gần nhất, để nhắc lưu bản sao ngoài máy chủ); cột `key_fingerprint` (16 hex, dấu vân tay của khóa mã hóa đã dùng, không chứa khóa) |
| Số điện thoại đăng nhập | `users.phone` lưu dạng chuẩn (0912345678) và **duy nhất TRONG từng doanh nghiệp** (chỉ mục duy nhất một phần `users_scope_phone_unique` trên `(COALESCE(tenant_id, 0), phone)` khi khác NULL; tài khoản quản trị nền tảng `tenant_id` NULL là một nhóm riêng; tính cả tài khoản đã xóa mềm). Hai doanh nghiệp được dùng chung một số vì mỗi doanh nghiệp có subdomain riêng nên đăng nhập luôn biết đang ở doanh nghiệp nào. Email vẫn duy nhất toàn hệ thống. Bảng `password_reset_tokens` (khóa là email, mã đã băm, không RLS, không đưa vào sao lưu) dùng cho quên mật khẩu |
| Notifications | **Đã có:** `notices` – mỗi dòng là MỘT thông báo trong ứng dụng cho MỘT người nhận (`user_id`, `type`, `level` `info\|warning\|critical` có CHECK, `title`, `body`, `data` jsonb, `read_at`); `tenant_id` là Tenant của người nhận, **NULL = thông báo của Platform Admin** (RLS: chỉ thấy ở chế độ nền tảng); index `(user_id, read_at, created_at)`; được dọn định kỳ (`agrihub:notices:prune`). Khai báo trong `backup.excluded_tenant_tables` (không đưa vào sao lưu theo Tenant) |
| Monitoring | **Đã có:** `system_heartbeats` (mỗi tiến trình nền — scheduler, queue worker — một dòng ghi đè mỗi phút: `beat_at`, `meta`) và `system_health_states` (trạng thái đã biết của từng thành phần để chỉ cảnh báo khi chuyển trạng thái: `status` có CHECK, `since`, `last_alerted_at`, `detail`). Không thuộc Tenant nào, không có RLS |
| Files | `files` (đường dẫn private, checksum, owner) |

## 3. Quy tắc kho (quan trọng)
- `stock_movements`: `tenant_id, branch_id, warehouse_id, product_id, batch_id, quantity_delta, unit_cost, movement_type, source_type/source_id, performed_by, performed_at, note`. **Chỉ INSERT**; chặn UPDATE/DELETE bằng trigger.
- `stock_balances` cập nhật trong cùng transaction với movement (khóa dòng `SELECT … FOR UPDATE`), có CHECK `>= 0`. **Không cho phép tồn âm** (đã chốt).
- Xuất kho ưu tiên FEFO theo HSD (cấu hình).

## 4. Quy tắc công nợ
- `receivables.amount_due = total - paid` được kiểm bằng tổng `receipt_allocations`; không lưu sửa tay.
- Một phiếu thu phân bổ nhiều hóa đơn qua `receipt_allocations`.
- Hủy thanh toán = tạo bút toán đảo, không xóa.

## 5. Audit
> Đã triển khai: xem docs/MONITORING_LOGGING.md mục 1.0. Bảng append-only, phân vùng theo tháng, RLS theo Tenant, không khóa ngoại. Mô tả dưới đây là thiết kế ban đầu.

`audit_logs`: `tenant_id, branch_id, actor_id, ip, user_agent, module, action, subject_type, subject_id, before JSONB, after JSONB, result, occurred_at`. Không ghi mật khẩu/token. Append-only.

## 6. Hiệu năng
Index theo truy vấn thực tế; partition `audit_logs`, `stock_movements` khi lớn; báo cáo nặng dùng read replica / materialized view (Giai đoạn 4).
