<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kết quả kiểm thử khôi phục của bản sao lưu toàn hệ thống (xem docs/BACKUP_DISASTER_RECOVERY.md mục 8).
 * Một bản sao lưu chỉ đáng tin khi đã được khôi phục thử thành công: `verify_status` ghi lại điều đó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('verify_status', 10)->nullable()->after('error'); // passed | failed
            $table->timestamp('verified_at')->nullable()->after('verify_status');
            $table->string('verify_error', 500)->nullable()->after('verified_at');
            $table->index(['scope', 'kind', 'status', 'created_at']);
        });

        // Bất biến ở mức database: bản toàn hệ thống không thuộc Tenant nào, bản của Tenant luôn thuộc một Tenant.
        DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_scope_tenant_check CHECK ((scope = 'platform' AND tenant_id IS NULL) OR (scope = 'tenant' AND tenant_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE backups DROP CONSTRAINT IF EXISTS backups_scope_tenant_check');

        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['scope', 'kind', 'status', 'created_at']);
            $table->dropColumn(['verify_status', 'verified_at', 'verify_error']);
        });
    }
};
