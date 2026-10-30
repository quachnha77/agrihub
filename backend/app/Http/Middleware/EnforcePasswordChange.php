<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tài khoản đang dùng mật khẩu tạm (do quản trị cấp / reset) phải đổi mật khẩu trước khi dùng bất kỳ chức năng nào.
 * Các route /auth/me, /auth/logout, /auth/password nằm ngoài nhóm này nên vẫn dùng được.
 */
class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return Problem::response(403, 'password_change_required', 'Bạn cần đổi mật khẩu tạm trước khi tiếp tục.');
        }

        return $next($request);
    }
}
