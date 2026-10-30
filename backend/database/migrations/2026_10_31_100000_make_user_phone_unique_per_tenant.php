<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mỗi doanh nghiệp một subdomain nên số điện thoại chỉ cần duy nhất TRONG doanh nghiệp (không còn duy nhất toàn hệ thống).
 * Tài khoản quản trị nền tảng (tenant_id NULL) là một nhóm riêng. COALESCE(tenant_id, 0) vì PostgreSQL coi hai NULL là khác nhau.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_phone_unique');
        DB::statement('CREATE UNIQUE INDEX users_scope_phone_unique ON users ((COALESCE(tenant_id, 0)), phone) WHERE phone IS NOT NULL');
    }

    /**
     * Quay lại duy nhất toàn hệ thống: nếu đã có số dùng chung giữa các doanh nghiệp thì tài khoản có id nhỏ nhất giữ số,
     * các tài khoản còn lại bị bỏ trống số (giống cách migration chuẩn hóa số điện thoại đã làm).
     */
    public function down(): void
    {
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            DB::statement('UPDATE users u SET phone = NULL WHERE phone IS NOT NULL AND EXISTS (
                SELECT 1 FROM users o WHERE o.phone = u.phone AND o.id < u.id
            )');
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }

        DB::statement('DROP INDEX IF EXISTS users_scope_phone_unique');
        DB::statement('CREATE UNIQUE INDEX users_phone_unique ON users (phone) WHERE phone IS NOT NULL');
    }
};
