<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\StockDocument;
use App\Models\Stocktake;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Observers\BranchObserver;
use App\Policies\CatalogListPolicy;
use App\Policies\CustomerGroupPolicy;
use App\Policies\StockDocumentPolicy;
use App\Policies\StocktakePolicy;
use App\Policies\WarehousePolicy;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\LoginIdentifier;
use App\Support\Auth\LoginScope;
use App\Support\Platform\SupportSession;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\HostTenant;
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
        $this->app->scoped(HostTenant::class);
    }

    public function boot(): void
    {
        // Mỗi quyền trong catalog là một Gate ability: Gate::allows('sales.order.create'), middleware can:...
        foreach (array_keys(Catalog::permissions()) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // Đơn vị tính, nhóm hàng, thương hiệu dùng chung một bộ quy tắc (Product có ProductPolicy riêng, tự nhận diện theo tên).
        foreach ([Unit::class, Category::class, Brand::class] as $model) {
            Gate::policy($model, CatalogListPolicy::class);
        }

        Gate::policy(CustomerGroup::class, CustomerGroupPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(StockDocument::class, StockDocumentPolicy::class);
        Gate::policy(Stocktake::class, StocktakePolicy::class);

        // Chi nhánh nào cũng có sẵn "Kho chính".
        Branch::observe(BranchObserver::class);

        Auth::provider('tenant-eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        // Tham số route là uuid công khai; giá trị khác định dạng => 404 (không để PostgreSQL báo lỗi cú pháp uuid thành 500).
        foreach (['tenant', 'user', 'branch', 'role', 'plan', 'backup', 'product', 'unit', 'category', 'brand', 'customer', 'customer_group', 'supplier', 'warehouse', 'document'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        // Thao tác nhạy cảm sinh/đổi mật khẩu (đổi mật khẩu, cấp mật khẩu tạm, tạo người dùng/doanh nghiệp): 10 lần/phút/người dùng.
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute((int) config('platform.rate_limits.sensitive'))->by('sensitive|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Mỗi doanh nghiệp một subdomain: mọi subdomain của tên miền gốc đều được coi là ứng dụng web (cookie phiên Sanctum).
        $stateful = HostTenant::statefulDomains(config('tenancy.base_domain'), config('tenancy.url_port'));
        if ($stateful !== []) {
            config(['sanctum.stateful' => array_values(array_unique(array_merge((array) config('sanctum.stateful'), $stateful)))]);
        }

        // Chống dò mật khẩu: theo cặp (email hoặc số điện thoại) + IP. Áp dụng cả cho tài khoản không tồn tại để không lộ thông tin.
        // Khóa dùng dạng đã chuẩn hóa nên viết số điện thoại theo kiểu khác không né được giới hạn.
        RateLimiter::for('login', function (Request $request) {
            $key = LoginIdentifier::throttleKey((string) $request->input('login', $request->input('email')), LoginScope::fromHost(app(HostTenant::class)));

            return [
                Limit::perMinute(5)->by('login|'.$key.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip|'.$request->ip()),
            ];
        });

        // Quên mật khẩu / quên tài khoản: mỗi lần chỉ gửi một email nên giới hạn chặt (chống dùng hệ thống để gửi thư rác cho người khác).
        RateLimiter::for('recovery', function (Request $request) {
            $key = LoginIdentifier::throttleKey((string) $request->input('login'), LoginScope::fromHost(app(HostTenant::class)));

            return [
                Limit::perMinute(3)->by('recovery|'.$key.'|'.$request->ip()),
                Limit::perHour(20)->by('recovery-ip|'.$request->ip()),
            ];
        });

        // Đặt lại mật khẩu bằng liên kết: mã trong liên kết dài và ngẫu nhiên nên không dò được; giới hạn để chặn lạm dụng.
        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinute(10)->by('reset-password|'.$request->ip()));
    }
}
