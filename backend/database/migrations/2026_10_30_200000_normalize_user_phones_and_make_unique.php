<?php

use App\Support\Auth\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cho phép đăng nhập bằng số điện thoại: chuẩn hóa số đã lưu (0912345678) và bắt buộc duy nhất toàn hệ thống.
 *
 * Số không phải số Việt Nam hợp lệ được giữ nguyên (không dùng để đăng nhập được). Nếu sau chuẩn hóa có nhiều tài khoản
 * trùng số, tài khoản có id nhỏ nhất giữ số, các tài khoản còn lại bị bỏ trống số (không thể có hai người cùng định danh).
 */
return new class extends Migration
{
    public function up(): void
    {
        // users có RLS FORCE: chạy ở chế độ nền tảng để thấy mọi dòng.
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            $seen = [];

            foreach (DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $user) {
                $normalized = Phone::normalize($user->phone);
                $final = $normalized ?? $user->phone; // số không hợp lệ giữ nguyên nhưng vẫn không được trùng nhau

                if (isset($seen[$final])) {
                    DB::table('users')->where('id', $user->id)->update(['phone' => null]);

                    continue;
                }

                $seen[$final] = true;

                if ($final !== $user->phone) {
                    DB::table('users')->where('id', $user->id)->update(['phone' => $final]);
                }
            }
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }

        DB::statement('CREATE UNIQUE INDEX users_phone_unique ON users (phone) WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_phone_unique');
    }
};
