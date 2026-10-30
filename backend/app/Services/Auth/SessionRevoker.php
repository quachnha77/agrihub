<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Thu hồi phiên đăng nhập (đăng xuất từ xa). Yêu cầu SESSION_DRIVER=database; token cá nhân (Sanctum) cũng bị xóa.
 */
class SessionRevoker
{
    /** @return int số phiên đã xóa */
    public function forUser(int $userId, ?string $exceptSessionId = null): int
    {
        $query = DB::table('sessions')->where('user_id', $userId);

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $count = $query->delete();

        if ($exceptSessionId === null) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->where('tokenable_id', $userId)
                ->delete();
        }

        return $count;
    }

    /**
     * Mọi phiên của người dùng thuộc một Tenant. Phải gọi ở chế độ nền tảng (bảng users có RLS).
     *
     * @return int số phiên đã xóa
     */
    public function forTenant(int $tenantId): int
    {
        $userIds = DB::table('users')->where('tenant_id', $tenantId)->select('id');

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();

        return DB::table('sessions')->whereIn('user_id', $userIds)->delete();
    }
}
