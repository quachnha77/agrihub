<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bán hàng (xem docs/DATABASE_DESIGN.md mục Bán hàng). Quy trình đơn giản cho cửa hàng nhỏ: MỘT bước — lập phiếu bán là hàng xuất kho ngay (không báo giá, không duyệt, không giao hàng
 * riêng), khách trả đủ (tiền mặt / chuyển khoản) hoặc ghi nợ phần còn lại; bán chịu bị chặn khi vượt hạn mức / quá hạn nợ của khách.
 *
 * - sales_orders: phiếu bán của MỘT kho (và chi nhánh của kho); `customer_id` có thể trống (khách lẻ, chỉ bán trả đủ). `goods_total` = tổng thành tiền các dòng; `discount` = số tiền bớt cho
 *   cả phiếu; `total` = tiền khách phải trả (= goods_total − discount, là số nợ của khách khi chưa trả); `paid_amount` là tổng các lần thu chưa hủy (số dẫn xuất, chỉ SalesService cập nhật
 *   trong cùng transaction với `sales_payments`, có CHECK 0 ≤ đã thu ≤ total); `due_on` = hạn trả nợ (ngày bán + số ngày nợ của khách, trống nếu không quy định). Phiếu không sửa/xóa:
 *   hàng đã ra sổ kho qua `stock_document_id`, sai sót xử lý bằng phiếu điều chỉnh / trả hàng (làm sau).
 * - sales_order_lines: mỗi dòng ứng với MỘT dòng sổ kho (sản phẩm theo dõi lô được tự chia theo lô gần hết hạn trước nên một dòng nhập có thể thành nhiều dòng phiếu); lưu đơn giá,
 *   thành tiền và giá vốn (`cost_amount`, theo giá vốn bình quân lúc bán) để tính lãi sau này.
 * - sales_payments: các lần thu tiền của khách (tiền mặt / chuyển khoản). Không xóa: thu nhầm thì đánh dấu hủy (`voided_at`), số đã thu của phiếu tự giảm.
 * Số tiền NUMERIC(18,2); mọi bảng có tenant_id và RLS (FORCE).
 */
return new class extends Migration
{
    private const TABLES = ['sales_orders', 'sales_order_lines', 'sales_payments'];

    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete(); // trống = khách lẻ
            $table->string('code', 30);
            $table->date('sold_on');
            $table->date('due_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->decimal('goods_total', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->foreignId('stock_document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'sold_on']);
            $table->index('customer_id');
            $table->index('warehouse_id');
            $table->unique('stock_document_id');
        });
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_amounts_check CHECK (goods_total >= 0 AND discount >= 0 AND discount <= goods_total AND total = goods_total - discount AND paid_amount >= 0 AND paid_amount <= total)');
        // Có nợ thì phải biết ai nợ: khách lẻ (không có hồ sơ) chỉ được mua trả đủ ngay lúc lập phiếu.
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_debtor_check CHECK (customer_id IS NOT NULL OR paid_amount = total)');

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); // đơn vị bán
            $table->decimal('quantity', 18, 3); // theo đơn vị bán
            $table->decimal('unit_price', 18, 2); // đơn giá bán mỗi đơn vị bán
            $table->decimal('line_total', 18, 2); // = làm tròn(số lượng × đơn giá), dòng chia lô: dòng cuối nhận phần lẻ nên tổng khớp
            $table->decimal('cost_amount', 18, 2)->default(0); // giá vốn của hàng đã bán
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('order_id');
            $table->index('product_id');
        });
        DB::statement('ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_lines_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0 AND cost_amount >= 0)');

        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20); // cash | bank_transfer
            $table->date('paid_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->index('order_id');
        });
        DB::statement("ALTER TABLE sales_payments ADD CONSTRAINT sales_payments_check CHECK (amount > 0 AND method IN ('cash', 'bank_transfer'))");

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
