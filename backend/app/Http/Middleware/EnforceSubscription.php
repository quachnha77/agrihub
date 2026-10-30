<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thuê bao hết hạn và hết ân hạn => doanh nghiệp chỉ được XEM dữ liệu (không khóa, không mất dữ liệu).
 * Chỉ áp dụng cho người dùng của Tenant; Platform Admin trong phiên hỗ trợ vốn đã chỉ đọc. Tenant chưa có thuê bao
 * (dữ liệu cũ / môi trường dev) không bị hạn chế.
 */
class EnforceSubscription
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_platform_admin || in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $subscription = Subscription::query()->first(); // TenantScope: thuê bao của chính Tenant

        if ($subscription?->isReadOnly()) {
            return Problem::response(402, 'subscription_expired',
                'Gói dịch vụ đã hết hạn, hệ thống đang ở chế độ chỉ xem. Vui lòng liên hệ AgriHub để gia hạn.');
        }

        return $next($request);
    }
}
