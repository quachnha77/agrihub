<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Doanh nghiệp (hoặc nền tảng) mà địa chỉ truy cập hiện tại trỏ tới: <slug>.<base_domain> hoặc admin.<base_domain>.
 * Chỉ để chọn trang đăng nhập và từ chối đăng nhập/phiên SAI ĐỊA CHỈ; không phải nguồn quyền truy cập (xem config/tenancy.php).
 *
 * Đối tượng "scoped": được nạp lại bởi middleware ResolveHostTenant cho mỗi request.
 */
final class HostTenant
{
    /** Tính năng tắt (chưa đặt TENANT_BASE_DOMAIN): không hạn chế gì. */
    public const UNRESTRICTED = 'unrestricted';

    /** Địa chỉ của quản trị nền tảng (tên miền gốc hoặc admin.<gốc>). */
    public const PLATFORM = 'platform';

    /** Địa chỉ của một doanh nghiệp có thật. */
    public const TENANT = 'tenant';

    /** Subdomain không có doanh nghiệp nào, hoặc tên máy chủ nằm ngoài tên miền gốc. */
    public const UNKNOWN = 'unknown';

    private string $kind = self::UNRESTRICTED;

    /** @var array{id: int, uuid: string, name: string, slug: string}|null */
    private ?array $tenant = null;

    public function resolve(Request $request): void
    {
        $this->kind = self::UNRESTRICTED;
        $this->tenant = null;

        $base = config('tenancy.base_domain');

        if (! is_string($base) || $base === '') {
            return;
        }

        $host = strtolower($request->getHost());

        if ($host === $base || in_array($host, array_map(fn (string $l) => "{$l}.{$base}", (array) config('tenancy.platform_labels')), true)) {
            $this->kind = self::PLATFORM;

            return;
        }

        $this->kind = self::UNKNOWN;

        if (! str_ends_with($host, ".{$base}")) {
            return;
        }

        $label = substr($host, 0, -(strlen($base) + 1));

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $label)) {
            return;
        }

        $tenant = $this->find($label);

        if ($tenant !== null) {
            $this->kind = self::TENANT;
            $this->tenant = $tenant;
        }
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function enforced(): bool
    {
        return $this->kind !== self::UNRESTRICTED;
    }

    public function tenantId(): ?int
    {
        return $this->tenant['id'] ?? null;
    }

    /** @return array{id: int, uuid: string, name: string, slug: string}|null */
    public function tenant(): ?array
    {
        return $this->tenant;
    }

    /** Đường dẫn đăng nhập của một doanh nghiệp; null khi tính năng đang tắt. */
    public function urlFor(string $slug): ?string
    {
        $base = config('tenancy.base_domain');

        return is_string($base) && $base !== '' ? $this->url("{$slug}.{$base}") : null;
    }

    /**
     * Địa chỉ đăng nhập riêng của một tài khoản: doanh nghiệp của họ, hoặc cổng quản trị nếu là Super Admin.
     * Khi chưa bật subdomain là địa chỉ chung của ứng dụng. Dựng từ cấu hình + dữ liệu, không từ Host của request.
     */
    public function loginUrlFor(User $user): ?string
    {
        if (! is_string(config('tenancy.base_domain')) || config('tenancy.base_domain') === '') {
            return config('tenancy.fallback_url');
        }

        if ($user->is_platform_admin) {
            return $this->platformUrl();
        }

        $slug = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->whereKey($user->tenant_id)->value('slug'));

        return is_string($slug) ? $this->urlFor($slug) : null;
    }

    public function platformUrl(): ?string
    {
        $base = config('tenancy.base_domain');
        $label = (array) config('tenancy.platform_labels');

        return is_string($base) && $base !== '' ? $this->url(($label[0] ?? null) ? "{$label[0]}.{$base}" : $base) : null;
    }

    /**
     * Các mẫu để thêm vào SANCTUM_STATEFUL_DOMAINS (hỗ trợ ký tự đại diện *): mọi subdomain của tên miền gốc đều là "ứng dụng web" của chúng ta.
     *
     * @return list<string>
     */
    public static function statefulDomains(?string $base, ?string $port): array
    {
        if ($base === null || $base === '') {
            return [];
        }

        $hosts = [$base, "*.{$base}"];

        return $port ? array_merge($hosts, array_map(fn (string $h) => "{$h}:{$port}", $hosts)) : $hosts;
    }

    private function url(string $host): string
    {
        $port = config('tenancy.url_port');

        return config('tenancy.scheme').'://'.$host.($port ? ":{$port}" : '');
    }

    /**
     * Tra doanh nghiệp theo slug. Chỉ nhớ kết quả TÌM THẤY (30 giây) để mỗi request không tốn thêm truy vấn mà doanh nghiệp mới
     * tạo xong dùng được ngay (không nhớ kết quả "không có").
     *
     * @return array{id: int, uuid: string, name: string, slug: string}|null
     */
    private function find(string $slug): ?array
    {
        $key = "tenant-host:{$slug}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->where('slug', $slug)->first());

        if ($tenant === null) {
            return null;
        }

        $data = ['id' => $tenant->id, 'uuid' => $tenant->uuid, 'name' => $tenant->name, 'slug' => $tenant->slug];
        Cache::put($key, $data, 30);

        return $data;
    }
}
