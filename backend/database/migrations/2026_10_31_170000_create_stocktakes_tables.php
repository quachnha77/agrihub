<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm kê kho (xem docs/DATABASE_DESIGN.md mục Kho). Một đợt kiểm kê (`stocktakes`) thuộc MỘT kho; khi bắt đầu, hệ thống chụp tồn hiện có của kho thành
 * các dòng đếm (`stocktake_lines`: sản phẩm + lô + tồn hệ thống). Người dùng nhập số đếm vào từng dòng; khi hoàn tất, chênh lệch được ghi bằng MỘT phiếu
 * điều chỉnh (lý do `count_correction`) qua StockService — kiểm kê không bao giờ sửa tồn trực tiếp, sổ kho vẫn là nơi duy nhất ghi nhận thay đổi.
 *
 * - Khóa kho: kho đang có đợt `counting` thì StockService từ chối mọi phiếu khác (chỉ chính đợt kiểm kê khi hoàn tất được ghi). Chỉ mục duy nhất một phần
 *   bên dưới bảo đảm mỗi kho chỉ có MỘT đợt đang đếm, kể cả khi hai yêu cầu bắt đầu cùng lúc.
 * - Không cần duyệt (chủ sản phẩm chọn 2026-10-31): hoàn tất là sửa tồn luôn. Đợt đã `completed`/`cancelled` không sửa nữa (service chặn).
 * - Số lượng NUMERIC(18,3) tính theo đơn vị cơ sở; số đếm giữ thêm đơn vị người dùng đã nhập (vd. 12 Bao) để hiển thị lại đúng như họ gõ.
 * Cả hai bảng có tenant_id và RLS như mọi bảng nghiệp vụ.
 */
return new class extends Migration
{
    private const TABLES = ['stocktakes', 'stocktake_lines'];

    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('status', 20)->default('counting'); // counting | completed | cancelled
            $table->string('note', 500)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('adjustment_document_id')->nullable()->constrained('stock_documents')->restrictOnDelete(); // phiếu điều chỉnh ghi chênh lệch (NULL = không có chênh lệch / đã hủy)
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'branch_id', 'started_at']);
            $table->index('warehouse_id');
        });
        DB::statement("ALTER TABLE stocktakes ADD CONSTRAINT stocktakes_status_check CHECK (status IN ('counting', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE stocktakes ADD CONSTRAINT stocktakes_finished_check CHECK ((status = 'counting') = (finished_at IS NULL))");
        DB::statement("CREATE UNIQUE INDEX stocktakes_one_counting_per_warehouse ON stocktakes (warehouse_id) WHERE status = 'counting'");

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->decimal('system_quantity', 18, 3); // tồn hệ thống lúc bắt đầu (đơn vị cơ sở)
            $table->decimal('counted_quantity', 18, 3)->nullable(); // số đếm quy về đơn vị cơ sở; NULL = chưa đếm
            $table->foreignId('counted_unit_id')->nullable()->constrained('units')->restrictOnDelete(); // đơn vị người dùng đã nhập số đếm
            $table->decimal('counted_entered_quantity', 18, 3)->nullable();
            $table->decimal('counted_factor', 18, 6)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('counted_at')->nullable();
            $table->timestamps();

            $table->index('stocktake_id');
            $table->index('product_id');
        });
        DB::statement('CREATE UNIQUE INDEX stocktake_lines_unique ON stocktake_lines (stocktake_id, product_id, COALESCE(batch_id, 0))');
        DB::statement('ALTER TABLE stocktake_lines ADD CONSTRAINT stocktake_lines_quantity_check CHECK (system_quantity >= 0 AND (counted_quantity IS NULL OR counted_quantity >= 0))');
        DB::statement('ALTER TABLE stocktake_lines ADD CONSTRAINT stocktake_lines_counted_check CHECK ((counted_quantity IS NULL) = (counted_unit_id IS NULL))');

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
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
    }
};
