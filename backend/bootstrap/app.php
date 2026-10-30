<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\SetTenantContext;
use App\Http\Problem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA dùng cookie phiên (Sanctum) cho các domain trong SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();

        // Context Tenant phải được đặt SAU xác thực và TRƯỚC route-model-binding (binding đi qua TenantScope).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);

        $middleware->alias([
            'tenant.context' => SetTenantContext::class,
            'tenant.user' => EnsureTenantUser::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Lỗi API theo RFC 7807. Tài nguyên của Tenant khác luôn trả 404 như "không tồn tại".
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? Problem::response(422, 'validation_failed', 'Dữ liệu không hợp lệ.', $e->getMessage(), ['errors' => $e->errors()])
                : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Problem::response(401, 'unauthenticated', 'Chưa đăng nhập hoặc phiên đã hết hạn.') : null;
        });
        // Laravel đổi AuthorizationException thành AccessDeniedHttpException trước khi gọi các renderer.
        $exceptions->render(function (AccessDeniedHttpException|AuthorizationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Problem::response(403, 'forbidden', 'Bạn không có quyền thực hiện thao tác này.') : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Problem::response(404, 'not_found', 'Không tìm thấy tài nguyên.') : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? Problem::response(429, 'too_many_attempts', 'Quá nhiều lần thử. Vui lòng thử lại sau.', null, [
                    'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
                ])->withHeaders($e->getHeaders())
                : null;
        });
    })->create();
