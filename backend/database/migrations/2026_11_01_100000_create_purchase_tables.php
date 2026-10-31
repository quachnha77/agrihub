<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mua hàng (xem docs/DATABASE_DESIGN.md mục Mua hàng). Chủ sản phẩm chốt 2026-11-01: CHỈ có Phiếu nhập hàng (chưa có Đơn mua), không cần duyệt, ghi nợ + trả tiền ngay tại phiếu,
 * chi phí kèm theo tính vào giá vốn (phân bổ theo giá trị từng dòng).
 *
 * - purchase_receipts: phiếu nhập hàng của MỘT kho (và chi nhánh của kho) từ MỘT nhà cung cấp (dùng chung cả doanh nghiệp). `goods_total` là tiền hàng phải trả nhà cung cấp;
 *   `extra_cost` là chi phí kèm theo (vận chuyển, bốc xếp...) chỉ cộng vào giá vốn, KHÔNG nằm trong số nợ nhà cung cấp; `paid_amount` là tổng các lần trả chưa hủy (số dẫn xuất,
 *   chỉ PurchaseService cập nhật trong cùng transaction với `purchase_payments`, có CHECK 0 ≤ đã trả ≤ tiền hàng). Phiếu không sửa/xóa: hàng đã vào sổ kho qua `stock_document_id`
 *   (phiếu nhập kho do StockService ghi), sai sót sửa bằng phiếu điều chỉnh.
 * - purchase_receipt_lines: từng dòng hàng (đơn vị và đơn giá theo cách người dùng nhập; thành tiền, phần chi phí phân bổ, lô).
 * - purchase_payments: các lần trả tiền nhà cung cấp (tiền mặt / chuyển khoản). Không xóa: trả nhầm thì đánh dấu hủy (`voided_at`), số đã trả của phiếu tự giảm.
 * Số tiền NUMERIC(18,2); mọi bảng có tenant_id và RLS (FORCE).
 */
return new class extends Migration
{
    private const TABLES = ['purchase_receipts', 'purchase_receipt_lines', 'purchase_payments'];

    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('supplier_ref', 50)->nullable(); // số hóa đơn / phiếu giao hàng của nhà cung cấp
            $table->date('received_on');
            $table->string('note', 500)->nullable();
            $table->decimal('goods_total', 18, 2);
            $table->decimal('extra_cost', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->foreignId('stock_document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'received_on']);
            $table->index('supplier_id');
            $table->index('warehouse_id');
            $table->unique('stock_document_id');
        });
        DB::statement('ALTER TABLE purchase_receipts ADD CONSTRAINT purchase_receipts_amounts_check CHECK (goods_total >= 0 AND extra_cost >= 0 AND paid_amount >= 0 AND paid_amount <= goods_total)');

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); // đơn vị người dùng đã nhập
            $table->decimal('quantity', 18, 3); // theo đơn vị đã nhập
            $table->decimal('unit_cost', 18, 4); // đơn giá nhập mỗi đơn vị đã nhập
            $table->decimal('line_total', 18, 2); // = làm tròn(số lượng × đơn giá)
            $table->decimal('extra_allocated', 18, 2)->default(0); // phần chi phí kèm theo phân bổ vào dòng này
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('receipt_id');
            $table->index('product_id');
        });
        DB::statement('ALTER TABLE purchase_receipt_lines ADD CONSTRAINT purchase_receipt_lines_amounts_check CHECK (quantity > 0 AND unit_cost >= 0 AND line_total >= 0 AND extra_allocated >= 0)');

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20); // cash | bank_transfer
            $table->date('paid_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->index('receipt_id');
        });
        DB::statement("ALTER TABLE purchase_payments ADD CONSTRAINT purchase_payments_check CHECK (amount > 0 AND method IN ('cash', 'bank_transfer'))");

        foreach (self::TABLES as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
