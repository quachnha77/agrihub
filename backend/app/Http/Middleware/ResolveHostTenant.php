<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Support\Tenancy\HostTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác định địa chỉ truy cập thuộc nền tảng hay doanh nghiệp nào (config/tenancy.php). Subdomain không có doanh nghiệp, hoặc tên máy chủ
 * ngoài tên miền gốc, bị từ chối với 404 (trừ /health*, để cân bằng tải gọi bằng IP/tên nội bộ vẫn kiểm tra được).
 */
class ResolveHostTenant
{
    public function __construct(private HostTenant $host) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->host->resolve($request);

        if ($this->host->kind() === HostTenant::UNKNOWN && $request->is('api/*') && ! $request->is('api/v1/health*')) {
            return Problem::response(404, 'tenant_not_found', 'Không tìm thấy doanh nghiệp tại địa chỉ này.');
        }

        return $next($request);
    }
}
