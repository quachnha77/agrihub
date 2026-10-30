<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chỉ Platform Admin. Chạy phần còn lại của request ở chế độ nền tảng (thấy mọi Tenant) rồi khôi phục context cũ.
 * Phải đứng trước route-model-binding để các model được nạp với đúng phạm vi.
 */
class EnsurePlatformAdmin
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            return Problem::response(403, 'forbidden', 'Bạn không có quyền thực hiện thao tác này.');
        }

        return $this->context->runAsPlatform(fn () => $next($request));
    }
}
