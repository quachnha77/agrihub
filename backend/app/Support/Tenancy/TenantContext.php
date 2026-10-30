<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Giữ Tenant hiện tại của request/job và đồng bộ sang PostgreSQL (biến phiên app.tenant_id / app.is_platform)
 * để Row-Level Security áp dụng. Không có context => không truy cập được dữ liệu Tenant (fail closed).
 *
 * Tenant chỉ được xác định từ danh tính đã xác thực hoặc job, không bao giờ từ dữ liệu client gửi lên.
 */
class TenantContext
{
    private ?int $tenantId = null;

    private bool $platform = false;

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function hasContext(): bool
    {
        return $this->platform || $this->tenantId !== null;
    }

    public function setTenant(int $tenantId): void
    {
        $this->apply($tenantId, false);
    }

    public function setPlatform(): void
    {
        $this->apply(null, true);
    }

    public function clear(): void
    {
        $this->apply(null, false);
    }

    /** Chạy một đoạn code với quyền nền tảng (bỏ qua giới hạn Tenant), rồi khôi phục context cũ. */
    public function runAsPlatform(Closure $callback): mixed
    {
        return $this->runWith(null, true, $callback);
    }

    public function runForTenant(int $tenantId, Closure $callback): mixed
    {
        return $this->runWith($tenantId, false, $callback);
    }

    private function runWith(?int $tenantId, bool $platform, Closure $callback): mixed
    {
        [$prevTenant, $prevPlatform] = [$this->tenantId, $this->platform];
        $this->apply($tenantId, $platform);

        try {
            return $callback();
        } finally {
            $this->apply($prevTenant, $prevPlatform);
        }
    }

    private function apply(?int $tenantId, bool $platform): void
    {
        $this->tenantId = $tenantId;
        $this->platform = $platform;

        // set_config(..., false) = theo phiên kết nối; luôn được đặt lại ở đầu/cuối request và job.
        DB::select("select set_config('app.tenant_id', ?, false), set_config('app.is_platform', ?, false)", [
            $tenantId === null ? '' : (string) $tenantId,
            $platform ? 'on' : 'off',
        ]);
    }
}
