<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('slug');
            $table->string('address')->nullable()->after('phone');
            $table->string('tax_code', 20)->nullable()->after('address'); // mã số thuế, dùng cho hóa đơn về sau
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });

        Schema::table('users', function (Blueprint $table) {
            // Tài khoản do quản trị viên tạo / reset dùng mật khẩu tạm: bắt buộc đổi trước khi dùng hệ thống.
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address', 'tax_code', 'suspension_reason']);
        });
    }
};
