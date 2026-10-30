<?php

namespace App\Services\Rbac;

use App\Models\User;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class PermissionResolver
{
    public function __construct(private TenantContext $context) {}

    /**
     * Quyền hiệu lực của user = hợp các vai trò, lấy scope rộng nhất cho mỗi quyền.
     * Platform Admin không có quyền Tenant nào.
     *
     * @return array<string, string> permission key => scope
     */
    public function forUser(User $user): array
    {
        if ($user->is_platform_admin || $user->tenant_id === null) {
            return [];
        }

        $rows = $this->context->runForTenant((int) $user->tenant_id, fn () => DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $user->id)
            ->get(['p.key', 'rp.scope']));

        $map = [];
        foreach ($rows as $row) {
            if (Catalog::scopeRank($row->scope) > Catalog::scopeRank($map[$row->key] ?? null)) {
                $map[$row->key] = $row->scope;
            }
        }

        ksort($map);

        return $map;
    }
}
