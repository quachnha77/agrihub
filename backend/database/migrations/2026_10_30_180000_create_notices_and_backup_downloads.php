<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thông báo trong ứng dụng (in-app) và ghi nhận lần tải bản sao lưu gần nhất.
 *
 * - notices: mỗi dòng là MỘT thông báo gửi cho MỘT người dùng (đã tách theo người nhận nên trạng thái "đã đọc" riêng).
 *   tenant_id = Tenant của người nhận; NULL = thông báo của Platform Admin (nhân viên AgriHub), chỉ thấy ở chế độ nền tảng.
 * - backups.downloaded_at: lần tải về gần nhất, để nhắc "chưa tải bản sao lưu ra ngoài" (xem PlatformBackupMonitor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80); // vd. backup.platform.failed
            $table->string('level', 10); // info | warning | critical
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE notices ADD CONSTRAINT notices_level_check CHECK (level IN ('info', 'warning', 'critical'))");

        $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
            ." OR current_setting('app.is_platform', true) = 'on')";

        DB::statement('ALTER TABLE notices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE notices FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON notices USING {$predicate} WITH CHECK {$predicate}");

        Schema::table('backups', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('verify_error');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('downloaded_at');
        });

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON notices');
        Schema::dropIfExists('notices');
    }
};
