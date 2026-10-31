<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kho (Giai đoạn 2, xem docs/DATABASE_DESIGN.md mục 3). Tồn kho KHÔNG sửa trực tiếp: mọi thay đổi là một dòng trong sổ kho
 * (`stock_movements`, chỉ ghi thêm) thuộc một phiếu kho (`stock_documents`); `stock_balances` là số dư dẫn xuất, cập nhật trong cùng
 * transaction với sổ và có CHECK >= 0 (không cho tồn âm).
 *
 * - warehouses: kho của một chi nhánh (nhiều kho mỗi chi nhánh); chi nhánh nào cũng có sẵn "Kho chính".
 * - batches: lô hàng của một sản phẩm (số lô, ngày sản xuất, hạn dùng). Sản phẩm thuộc một chi nhánh nên lô cũng vậy.
 * - product_costs: giá vốn bình quân gia quyền của sản phẩm (đơn vị cơ sở). Dòng này cũng là "khóa" tuần tự hóa mọi thay đổi tồn của
 *   cùng một sản phẩm (SELECT ... FOR UPDATE) nên giá vốn không bao giờ bị tính sai khi hai phiếu chạy đồng thời.
 * - stock_documents / stock_movements: sổ kho. Chặn UPDATE/DELETE bằng trigger; ngoại lệ duy nhất là khôi phục sao lưu của doanh nghiệp,
 *   đặt `app.ledger_restore = on` trong transaction khôi phục để được XÓA (không bao giờ sửa) rồi nạp lại từ bản sao lưu.
 * - products.min_stock: mức tồn tối thiểu (đơn vị cơ sở) để cảnh báo sắp hết hàng.
 * Số lượng NUMERIC(18,3) tính theo đơn vị cơ sở của sản phẩm; giá vốn NUMERIC(18,4) mỗi đơn vị cơ sở. Mọi bảng có tenant_id và RLS (FORCE).
 */
return new class extends Migration
{
    private const TABLES = ['warehouses', 'batches', 'product_costs', 'stock_documents', 'stock_movements', 'stock_balances'];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('min_stock', 18, 3)->nullable(); // đơn vị cơ sở; NULL = không đặt
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_min_stock_check CHECK (min_stock IS NULL OR min_stock >= 0)');

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('address')->nullable();
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'branch_id', 'status']);
        });
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('CREATE UNIQUE INDEX warehouses_branch_code_unique ON warehouses (branch_id, lower(code)) WHERE deleted_at IS NULL');

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('batch_no', 50);
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });
        DB::statement('CREATE UNIQUE INDEX batches_product_batch_no_unique ON batches (product_id, lower(batch_no))');
        DB::statement('ALTER TABLE batches ADD CONSTRAINT batches_dates_check CHECK (manufactured_at IS NULL OR expires_at IS NULL OR manufactured_at <= expires_at)');

        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete()->unique();
            $table->decimal('avg_cost', 18, 4)->default(0); // giá vốn bình quân mỗi đơn vị cơ sở
            $table->timestamps();
        });
        DB::statement('ALTER TABLE product_costs ADD CONSTRAINT product_costs_avg_cost_check CHECK (avg_cost >= 0)');

        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('type', 20); // opening | receipt | issue | adjustment
            $table->string('reason', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('performed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'performed_at']);
            $table->index('warehouse_id');
        });
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('opening', 'receipt', 'issue', 'adjustment'))");

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->string('movement_type', 20); // cùng giá trị với loại phiếu
            $table->decimal('quantity_delta', 18, 3); // đơn vị cơ sở; dương = nhập, âm = xuất
            $table->decimal('balance_after', 18, 3); // số dư của kho + sản phẩm + lô sau dòng này
            $table->decimal('unit_cost', 18, 4); // giá vốn mỗi đơn vị cơ sở tại thời điểm ghi
            $table->foreignId('entered_unit_id')->constrained('units')->restrictOnDelete(); // đơn vị người dùng đã nhập
            $table->decimal('entered_quantity', 18, 3);
            $table->decimal('entered_factor', 18, 6);
            $table->timestampTz('performed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_id', 'performed_at']);
            $table->index(['warehouse_id', 'product_id']);
            $table->index('document_id');
        });
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('opening', 'receipt', 'issue', 'adjustment'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_delta_check CHECK (quantity_delta <> 0 AND balance_after >= 0 AND unit_cost >= 0)');

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['tenant_id', 'product_id']);
            $table->index('batch_id');
        });
        DB::statement('CREATE UNIQUE INDEX stock_balances_unique ON stock_balances (warehouse_id, product_id, COALESCE(batch_id, 0))');
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantity_check CHECK (quantity >= 0)');

        // Sổ kho chỉ ghi thêm. Khôi phục sao lưu của doanh nghiệp (TenantRestorer) bật `app.ledger_restore` trong transaction của nó để XÓA dòng cũ.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.ledger_restore', true) = 'on' THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION '% là sổ kho chỉ ghi thêm (append-only): thao tác % bị cấm', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);
        DB::unprepared('CREATE TRIGGER stock_documents_append_only BEFORE UPDATE OR DELETE ON stock_documents FOR EACH ROW EXECUTE FUNCTION stock_ledger_immutable()');
        DB::unprepared('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION stock_ledger_immutable()');

        foreach (self::TABLES as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }

        // Chi nhánh đã có từ trước: tạo sẵn "Kho chính" (chi nhánh mới được tạo kho này tự động bởi BranchObserver).
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            DB::statement(<<<'SQL'
                INSERT INTO warehouses (tenant_id, branch_id, code, name, status, created_at, updated_at)
                SELECT tenant_id, id, 'KHO-01', 'Kho chính', 'active', now(), now() FROM branches WHERE deleted_at IS NULL
            SQL);
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_documents_append_only ON stock_documents');

        foreach (['stock_balances', 'stock_movements', 'stock_documents', 'product_costs', 'batches', 'warehouses'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('DROP FUNCTION IF EXISTS stock_ledger_immutable()');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_min_stock_check');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_stock');
        });
    }
};
