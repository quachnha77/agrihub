<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sao lưu / khôi phục dữ liệu chỉ còn ở Super Admin (yêu cầu 2026-10-31): doanh nghiệp không còn quyền `backup.backup.*`
 * và không còn nhận thông báo về sao lưu.
 * Xóa các quyền này khỏi catalog trong DB; các dòng gán quyền cho vai trò (kể cả vai trò tùy chỉnh) tự mất theo (ON DELETE CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chế độ nền tảng để việc xóa theo khóa ngoại vào `role_permissions` (RLS FORCE) chắc chắn thấy mọi doanh nghiệp.
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            DB::table('permissions')->where('key', 'like', 'backup.backup.%')->delete();

            // Thông báo sao lưu/khôi phục cũ của người dùng doanh nghiệp dặn "vào mục Sao lưu để tải về": mục đó không còn nên xóa.
            // (Thông báo của Super Admin giữ nguyên.)
            DB::table('notices')->where('type', 'like', 'backup.%')
                ->whereIn('user_id', DB::table('users')->where('is_platform_admin', false)->select('id'))->delete();
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }
    }

    /**
     * Không khôi phục được dữ liệu đã xóa: nếu cần quay lại, đưa `backup.backup` về lại config/rbac.php rồi chạy `agrihub:sync-permissions`
     * (Owner được cấp đủ quyền tự động; vai trò khác phải cấp lại).
     */
    public function down(): void {}
};
