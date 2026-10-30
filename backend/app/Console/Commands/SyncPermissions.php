<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Rbac\RoleProvisioner;
use App\Services\Rbac\RoleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'agrihub:sync-permissions';

    protected $description = 'Đồng bộ catalog quyền (config/rbac.php) vào DB; bổ sung vai trò hệ thống còn thiếu và đảm bảo Owner đủ quyền cho mọi Tenant';

    public function handle(RoleService $roles, RoleProvisioner $provisioner, TenantContext $context): int
    {
        $count = $context->runAsPlatform(function () use ($roles, $provisioner) {
            $roles->syncCatalog();

            $tenants = Tenant::withTrashed()->get();
            $tenants->each(fn (Tenant $tenant) => $provisioner->provision($tenant));

            return $tenants->count();
        });

        $this->info("Đã đồng bộ catalog quyền và vai trò hệ thống cho {$count} doanh nghiệp.");
        $this->line('Lưu ý: quyền của các vai trò khác Owner đã được Tenant tùy chỉnh sẽ không bị ghi đè.');

        return self::SUCCESS;
    }
}
