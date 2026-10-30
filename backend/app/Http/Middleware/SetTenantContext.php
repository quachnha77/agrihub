<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\SupportSession;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chạy SAU xác thực và TRƯỚC route-model-binding. Tenant luôn lấy từ user đã đăng nhập (hoặc phiên hỗ trợ do server
 * giữ), không bao giờ từ request. Từ chối user bị vô hiệu hóa hoặc thuộc Tenant đang bị khóa, kể cả khi phiên vẫn còn.
 *
 * Platform Admin KHÔNG có context mặc định: route nền tảng tự chuyển sang chế độ nền tảng (EnsurePlatformAdmin);
 * route dữ liệu Tenant chỉ dùng được trong phiên hỗ trợ đang hiệu lực (chế độ chỉ đọc).
 */
class SetTenantContext
{
    public function __construct(
        private TenantContext $context,
        private SupportSession $support,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        try {
            if ($user->is_platform_admin) {
                $session = $this->support->resolve($request, $user);

                if ($expired = $this->support->takeExpired()) {
                    $this->audit->record(
                        'platform', 'support_session_expired',
                        tenantId: $expired['tenant_id'], actor: $user,
                        metadata: ['support_session' => $expired['id']],
                    );
                }

                $user->flushPermissionCache(); // quyền (kể cả quyền của phiên hỗ trợ) luôn được tính lại cho mỗi request

                if (! $user->isActive()) {
                    return Problem::response(403, 'account_disabled', 'Tài khoản đã bị vô hiệu hóa.');
                }

                if ($session !== null) {
                    $this->context->setTenant((int) $session['tenant_id']);
                }

                return $next($request);
            }

            $user->flushPermissionCache();
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
