<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chuyển kho (xem docs/DATABASE_DESIGN.md mục Kho). Chủ sản phẩm chốt 2026-10-31: LUÔN MỘT BƯỚC, cả trong cùng chi nhánh lẫn giữa hai chi nhánh — kho gửi bị trừ
 * và kho nhận được cộng ngay trong cùng một transaction, không có trạng thái "đang chuyển".
 *
 * - stock_transfers: phiếu chuyển (mã CKxxxx) nói "chuyển từ kho nào sang kho nào, ai làm, lúc nào". Bất biến như sổ kho (cùng trigger): sai sót sửa bằng một phiếu
 *   chuyển ngược lại hoặc phiếu điều chỉnh. Không có dòng riêng: các dòng chính là dòng sổ kho của phiếu xuất (`transfer_out`).
 * - Mỗi lần chuyển sinh HAI phiếu kho bình thường, cùng trỏ về phiếu chuyển bằng `stock_documents.transfer_id`: `transfer_out` (CKxxxx-X) ở kho gửi và `transfer_in`
 *   (CKxxxx-N) ở kho nhận. Nhờ vậy thẻ kho của MỖI chi nhánh vẫn đầy đủ (mỗi phiếu thuộc đúng một kho, một chi nhánh), tồn không âm và sổ kho bất biến như mọi phiếu.
 * - Hai loại phiếu mới được thêm vào CHECK của `stock_documents.type` và `stock_movements.movement_type`.
 * Bảng mới có tenant_id và RLS như mọi bảng nghiệp vụ.
 */
return new class extends Migration
{
    private const TYPES = "'opening', 'receipt', 'issue', 'adjustment'";

    private const TYPES_WITH_TRANSFER = "'opening', 'receipt', 'issue', 'adjustment', 'transfer_out', 'transfer_in'";

    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('performed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'performed_at']);
            $table->index('from_warehouse_id');
            $table->index('to_warehouse_id');
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_different_warehouses_check CHECK (from_warehouse_id <> to_warehouse_id)');

        DB::unprepared('CREATE TRIGGER stock_transfers_append_only BEFORE UPDATE OR DELETE ON stock_transfers FOR EACH ROW EXECUTE FUNCTION stock_ledger_immutable()');

        $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
            ." OR current_setting('app.is_platform', true) = 'on')";

        DB::statement('ALTER TABLE stock_transfers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE stock_transfers FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON stock_transfers USING {$predicate} WITH CHECK {$predicate}");

        // Cột thêm vào bảng sổ kho: trigger chỉ chặn UPDATE/DELETE dòng, không chặn đổi cấu trúc.
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->constrained('stock_transfers')->restrictOnDelete();
            $table->index('transfer_id');
        });

        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT stock_documents_type_check');
        DB::statement('ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('.self::TYPES_WITH_TRANSFER.'))');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('.self::TYPES_WITH_TRANSFER.'))');

        // Phiếu chuyển kho và chỉ phiếu chuyển kho mới có transfer_id (và ngược lại).
        DB::statement("ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_transfer_check CHECK ((type IN ('transfer_out', 'transfer_in')) = (transfer_id IS NOT NULL))");
    }

    /** Chỉ lùi được khi chưa có phiếu chuyển kho nào (CHECK cũ không cho phép loại phiếu mới). */
    public function down(): void
    {
        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT IF EXISTS stock_documents_transfer_check');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('.self::TYPES.'))');
        DB::statement('ALTER TABLE stock_documents DROP CONSTRAINT stock_documents_type_check');
        DB::statement('ALTER TABLE stock_documents ADD CONSTRAINT stock_documents_type_check CHECK (type IN ('.self::TYPES.'))');

        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS stock_transfers_append_only ON stock_transfers');
        Schema::dropIfExists('stock_transfers');
    }
};
