<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sao lưu / khôi phục theo Tenant (xem docs/BACKUP_DISASTER_RECOVERY.md).
 *
 * - backups: danh sách bản sao lưu (theo ngày giờ); file nằm trên disk riêng tư, đã mã hóa.
 * - restore_runs: mỗi lần khôi phục (ai làm, từ bản nào, kết quả, điểm quay lại trước khi khôi phục).
 * - tenants.restoring_at: cờ "đang khôi phục" – Tenant tạm ngừng nhận request (bảo trì).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete(); // null = toàn hệ thống (bước 8b)
            $table->string('scope', 10); // tenant | platform
            $table->string('kind', 12); // manual | auto | upload | pre_restore
            $table->string('status', 12); // pending | running | completed | failed
            $table->string('disk', 40);
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedSmallInteger('format_version');
            $table->string('schema_version', 120)->nullable(); // migration mới nhất tại thời điểm sao lưu
            $table->jsonb('row_counts')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // không FK: giữ nguyên khi user bị xóa
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('restore_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_id')->constrained('backups')->restrictOnDelete();
            $table->foreignId('pre_backup_id')->nullable()->constrained('backups')->restrictOnDelete(); // điểm quay lại
            $table->string('status', 12); // pending | running | completed | failed
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->jsonb('row_counts')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('restoring_at')->nullable()->after('suspension_reason');
        });

        foreach (['backups', 'restore_runs'] as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (['restore_runs', 'backups'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('restoring_at');
        });

        Schema::dropIfExists('restore_runs');
        Schema::dropIfExists('backups');
    }
};
