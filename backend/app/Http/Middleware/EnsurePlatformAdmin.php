<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_platform_admin) {
            return Problem::response(403, 'forbidden', 'Bạn không có quyền thực hiện thao tác này.');
        }

        return $next($request);
    }
}
