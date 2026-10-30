<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chạy SAU xác thực. Tenant luôn lấy từ user đã đăng nhập, không bao giờ từ request.
 * Từ chối user bị vô hiệu hóa hoặc thuộc Tenant đang bị khóa, kể cả khi phiên vẫn còn hiệu lực.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        try {
            if ($user === null) {
                return $next($request);
            }

            if ($user->is_platform_admin) {
                if (! $user->isActive()) {
                    return Problem::response(403, 'account_disabled', 'Tài khoản đã bị vô hiệu hóa.');
                }
                $this->context->setPlatform();

                return $next($request);
            }

            $this->context->setTenant((int) $user->tenant_id);

            if (! $user->isActive()) {
                return Problem::response(403, 'account_disabled', 'Tài khoản đã bị vô hiệu hóa.');
            }

            if (! $user->tenant?->isActive()) {
                return Problem::response(403, 'tenant_suspended', 'Doanh nghiệp đang bị tạm khóa. Vui lòng liên hệ AgriHub.');
            }

            return $next($request);
        } finally {
            // Không để context rò sang request/job kế tiếp trên cùng kết nối.
            $this->context->clear();
        }
    }
}
