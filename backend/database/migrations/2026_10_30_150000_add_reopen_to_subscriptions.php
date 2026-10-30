<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Nhân viên AgriHub mở lại tạm thời cho Tenant đã hết hạn.
            $table->timestamp('reopened_until')->nullable()->after('period_end');
            $table->string('reopen_reason', 500)->nullable()->after('reopened_until');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['reopened_until', 'reopen_reason']);
        });
    }
};
