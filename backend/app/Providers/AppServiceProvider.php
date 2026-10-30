<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\SupportSession;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantAwareUserProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: được tạo lại cho mỗi request/job (an toàn với queue worker lâu dài).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AuditLogger::class);
        $this->app->scoped(SupportSession::class);
    }

    public function boot(): void
    {
        // Mỗi quyền trong catalog là một Gate ability: Gate::allows('sales.order.create'), middleware can:...
        foreach (array_keys(Catalog::permissions()) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        Auth::provider('tenant-eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        // Tham số route là uuid công khai; giá trị khác định dạng => 404 (không để PostgreSQL báo lỗi cú pháp uuid thành 500).
        foreach (['tenant', 'user', 'branch', 'role', 'plan'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        // Thao tác nhạy cảm sinh/đổi mật khẩu (đổi mật khẩu, cấp mật khẩu tạm, tạo người dùng/doanh nghiệp): 10 lần/phút/người dùng.
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute((int) config('platform.rate_limits.sensitive'))->by('sensitive|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Chống dò mật khẩu: theo cặp email + IP. Áp dụng cả cho email không tồn tại để không lộ thông tin tài khoản.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login|'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip|'.$request->ip()),
            ];
        });
    }
}
