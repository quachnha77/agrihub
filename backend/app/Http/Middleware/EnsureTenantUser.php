<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Endpoint dữ liệu Tenant: Platform Admin không được vào (cần cơ chế impersonation có audit, làm sau). */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_platform_admin) {
            return Problem::response(403, 'tenant_context_required', 'Endpoint này chỉ dành cho người dùng của doanh nghiệp.');
        }

        return $next($request);
    }
}
