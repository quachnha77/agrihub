<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mỗi doanh nghiệp có tài khoản riêng nên email chỉ cần duy nhất TRONG doanh nghiệp (không còn duy nhất toàn hệ thống).
 * Tài khoản quản trị nền tảng (tenant_id NULL) là một nhóm riêng. COALESCE(tenant_id, 0) vì PostgreSQL coi hai NULL là khác nhau.
 *
 * Mã đặt lại mật khẩu trước đây khóa theo email; nay khóa theo tài khoản (User::getEmailForPasswordReset) để hai tài khoản cùng email
 * không dùng nhầm mã của nhau. Mã cũ chỉ sống 30 phút nên xóa hết là an toàn (người dùng chỉ cần yêu cầu lại).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_scope_email_unique ON users ((COALESCE(tenant_id, 0)), email)');

        DB::table('password_reset_tokens')->delete();
    }

    /**
     * Quay lại duy nhất toàn hệ thống. Email không bỏ trống được (khác số điện thoại), nên nếu đã có email dùng ở nhiều nơi thì dừng lại
     * và nói rõ để người vận hành tự quyết định xử lý tài khoản nào — không âm thầm sửa hay xóa dữ liệu đăng nhập.
     */
    public function down(): void
    {
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            $shared = DB::table('users')->select('email')->groupBy('email')->havingRaw('count(*) > 1')->pluck('email');
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }

        if ($shared->isNotEmpty()) {
            throw new RuntimeException('Không thể quay lại email duy nhất toàn hệ thống vì còn '.$shared->count().' email đang dùng ở nhiều tài khoản: '.$shared->take(5)->implode(', ').'. Hãy đổi hoặc xóa các tài khoản trùng trước.');
        }

        DB::statement('DROP INDEX IF EXISTS users_scope_email_unique');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
    }
};
