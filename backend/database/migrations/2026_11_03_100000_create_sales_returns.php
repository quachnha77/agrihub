<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trả hàng (xem docs/DATABASE_DESIGN.md mục Bán hàng). Khách trả lại hàng đã mua: hàng NHẬP LẠI kho bán ban đầu (phiếu nhập kho do hệ thống ghi), số tiền hàng trả lại trừ
 * vào nợ của phiếu trước, phần còn lại (khách đã trả rồi) hoàn lại cho khách. Phiếu bán gốc vẫn bất biến: chỉ thêm `returned_amount`.
 *
 * - sales_orders.returned_amount: tổng giá trị hàng khách đã trả lại (đã chia đều phần bớt tiền của cả phiếu). Tiền khách phải trả THỰC = total − returned_amount, nên CHECK mới:
 *   0 ≤ returned_amount ≤ total và 0 ≤ paid_amount ≤ total − returned_amount (khách lẻ phải luôn trả đủ phần còn lại: paid_amount = total − returned_amount).
 *   Không dùng cột tự tính để sao lưu/khôi phục nạp được mọi cột.
 * - sales_order_lines.uuid: để biết dòng nào của phiếu được trả lại.
 * - sales_returns: một lần trả hàng của MỘT phiếu bán (mã `TH0001`), nhập lại kho qua `stock_document_id`. `total` = giá trị trả lại = `debt_reduction` (trừ vào nợ) + `refund_amount`
 *   (hoàn tiền cho khách, đã trừ khỏi `paid_amount` của phiếu). Bất biến, không sửa/xóa.
 * - sales_return_lines: dòng của lần trả, tham chiếu dòng phiếu bán gốc; số lượng theo đơn vị bán; giá vốn lúc bán (hàng nhập lại theo đúng giá vốn đó).
 * Số tiền NUMERIC(18,2); mọi bảng mới có tenant_id và RLS (FORCE).
 */
return new class extends Migration
{
    private const TABLES = ['sales_returns', 'sales_return_lines'];

    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('returned_amount', 18, 2)->default(0);
        });
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_orders_amounts_check');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_orders_debtor_check');
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_amounts_check CHECK (goods_total >= 0 AND discount >= 0 AND discount <= goods_total AND total = goods_total - discount AND returned_amount >= 0 AND returned_amount <= total AND paid_amount >= 0 AND paid_amount <= total - returned_amount)');
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_debtor_check CHECK (customer_id IS NOT NULL OR paid_amount = total - returned_amount)');

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 30);
            $table->date('returned_on');
            $table->string('note', 500)->nullable();
            $table->decimal('total', 18, 2); // giá trị hàng trả lại (đã chia phần bớt tiền của phiếu)
            $table->decimal('debt_reduction', 18, 2)->default(0); // phần trừ vào nợ còn lại của phiếu
            $table->decimal('refund_amount', 18, 2)->default(0); // phần hoàn tiền cho khách
            $table->string('refund_method', 20)->nullable(); // cash | bank_transfer, trống khi không hoàn tiền
            $table->foreignId('stock_document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->unique('stock_document_id');
            $table->index('order_id');
            $table->index(['tenant_id', 'branch_id', 'returned_on']);
        });
        DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_amounts_check CHECK (total >= 0 AND debt_reduction >= 0 AND refund_amount >= 0 AND total = debt_reduction + refund_amount AND ((refund_amount = 0 AND refund_method IS NULL) OR (refund_amount > 0 AND refund_method IN ('cash', 'bank_transfer'))))");

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->decimal('quantity', 18, 3); // theo đơn vị bán của dòng phiếu bán
            $table->decimal('line_total', 18, 2); // giá trị trả lại của dòng, theo giá bán (chưa chia phần bớt tiền của phiếu)
            $table->decimal('cost_amount', 18, 2)->default(0); // giá vốn của hàng nhập lại kho
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index('return_id');
            $table->index('order_line_id');
        });
        DB::statement('ALTER TABLE sales_return_lines ADD CONSTRAINT sales_return_lines_amounts_check CHECK (quantity > 0 AND line_total >= 0 AND cost_amount >= 0)');

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

        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_orders_amounts_check');
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_orders_debtor_check');
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('returned_amount');
        });
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_amounts_check CHECK (goods_total >= 0 AND discount >= 0 AND discount <= goods_total AND total = goods_total - discount AND paid_amount >= 0 AND paid_amount <= total)');
        DB::statement('ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_debtor_check CHECK (customer_id IS NOT NULL OR paid_amount = total)');

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
