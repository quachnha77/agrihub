<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giám sát hạ tầng (không thuộc Tenant nào, không có RLS).
 *
 * - system_heartbeats: tín hiệu sống gần nhất của từng thành phần nền (scheduler, queue worker): mỗi phút ghi đè một dòng.
 * - system_health_states: trạng thái đã biết của từng thành phần để chỉ cảnh báo khi CHUYỂN trạng thái (và nhắc lại có chu kỳ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->timestamp('beat_at');
            $table->jsonb('meta')->nullable();
        });

        Schema::create('system_health_states', function (Blueprint $table) {
            $table->string('component', 40)->primary();
            $table->string('status', 10); // ok | warning | critical
            $table->timestamp('since');
            $table->timestamp('last_alerted_at')->nullable();
            $table->text('detail')->nullable();
        });

        DB::statement("ALTER TABLE system_health_states ADD CONSTRAINT system_health_states_status_check CHECK (status IN ('ok', 'warning', 'critical'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health_states');
        Schema::dropIfExists('system_heartbeats');
    }
};
