<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dấu vân tay (không đảo ngược được) của khóa mã hóa đã dùng cho từng bản sao lưu, để biết mỗi file cần khóa nào
 * khi khóa được thay đổi theo thời gian. Không chứa khóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('key_fingerprint', 16)->nullable()->after('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('key_fingerprint');
        });
    }
};
