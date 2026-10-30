<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditSuppression;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantContext;

/** Tạo/đồng bộ các vai trò hệ thống cho một Tenant. */
class RoleProvisioner
{
    public function __construct(private RoleService $roles, private TenantContext $context, private AuditLogger $audit) {}

    /**
     * Tạo các vai trò hệ thống còn thiếu (với quyền mặc định). Vai trò đã có KHÔNG bị ghi đè (Tenant có thể đã tùy chỉnh),
     * ngoại trừ Owner luôn được đảm bảo có đủ mọi quyền.
     *
     * Việc này ghi MỘT sự kiện audit tổng hợp thay vì từng vai trò.
     */
    public function provision(Tenant $tenant): void
    {
        $this->context->runAsPlatform(function () use ($tenant) {
            $this->roles->syncCatalog();
            $created = [];
            $restoredOwner = false;

            AuditSuppression::run(function () use ($tenant, &$created, &$restoredOwner) {
                foreach (Catalog::roleKeys() as $key) {
                    $config = config("rbac.roles.{$key}");

                    $role = Role::where('tenant_id', $tenant->id)->where('key', $key)->first();

                    if ($role === null) {
                        $role = new Role(['name' => $config['name'], 'description' => $config['description']]);
                        $role->forceFill(['tenant_id' => $tenant->id, 'key' => $key, 'is_system' => true])->save();
                        $this->roles->setGrants($role, Catalog::roleGrants($key));
                        $created[] = $key;
                    } elseif ($key === Role::OWNER) {
                        $before = $this->roles->grants($role);
                        $this->roles->setGrants($role, Catalog::roleGrants($key));
                        $restoredOwner = $before != Catalog::roleGrants($key);
                    }
                }
            });

            if ($created !== [] || $restoredOwner) {
                $this->audit->record(
                    'rbac', 'system_roles_provisioned',
                    subject: $tenant,
                    metadata: ['created' => $created, 'owner_permissions_restored' => $restoredOwner],
                );
            }
        });
    }
}
