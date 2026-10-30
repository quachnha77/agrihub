<?php

namespace App\Providers;

use App\Support\Tenancy\TenantAwareUserProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: được tạo lại cho mỗi request/job (an toàn với queue worker lâu dài).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Auth::provider('tenant-eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

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
