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
| Subscription/Billing | `subscriptions`, `subscription_periods` (snapshot số user + giá), `subscription_changes`, `invoices_saas`, `payment_transactions` (trạng thái xác minh) |
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
| Files | `files` (đường dẫn private, checksum, owner) |

## 3. Quy tắc kho (quan trọng)
- `stock_movements`: `tenant_id, branch_id, warehouse_id, product_id, batch_id, quantity_delta, unit_cost, movement_type, source_type/source_id, performed_by, performed_at, note`. **Chỉ INSERT**; chặn UPDATE/DELETE bằng trigger.
- `stock_balances` cập nhật trong cùng transaction với movement (khóa dòng `SELECT … FOR UPDATE`), có CHECK `>= 0` trừ khi tenant cho phép âm kho.
- Xuất kho ưu tiên FEFO theo HSD (cấu hình).

## 4. Quy tắc công nợ
- `receivables.amount_due = total - paid` được kiểm bằng tổng `receipt_allocations`; không lưu sửa tay.
- Một phiếu thu phân bổ nhiều hóa đơn qua `receipt_allocations`.
- Hủy thanh toán = tạo bút toán đảo, không xóa.

## 5. Audit
`audit_logs`: `tenant_id, branch_id, actor_id, ip, user_agent, module, action, subject_type, subject_id, before JSONB, after JSONB, result, occurred_at`. Không ghi mật khẩu/token. Append-only.

## 6. Hiệu năng
Index theo truy vấn thực tế; partition `audit_logs`, `stock_movements` khi lớn; báo cáo nặng dùng read replica / materialized view (Giai đoạn 4).
