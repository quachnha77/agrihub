<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint dữ liệu Tenant. Platform Admin chỉ vào được trong phiên hỗ trợ đang hiệu lực, và chỉ để ĐỌC;
 * mỗi lượt truy cập được ghi audit (Tenant thấy được trong nhật ký của mình).
 */
class EnsureTenantUser
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private SupportSession $support, private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_platform_admin) {
            if (! $this->support->active()) {
                return Problem::response(403, 'tenant_context_required', 'Endpoint này chỉ dành cho người dùng của doanh nghiệp.');
            }

            if (! in_array($request->method(), self::SAFE_METHODS, true)) {
                return Problem::response(403, 'support_session_read_only', 'Phiên hỗ trợ chỉ cho phép xem dữ liệu.');
            }

            // Chỉ ghi đường dẫn, không ghi query string (có thể chứa từ khóa tìm kiếm / dữ liệu cá nhân).
            $this->audit->record(
                'platform', 'support_request',
                tenantId: $this->support->tenantId(), actor: $user,
                metadata: ['method' => $request->method(), 'path' => '/'.$request->path()],
            );
        }

        return $next($request);
    }
}
