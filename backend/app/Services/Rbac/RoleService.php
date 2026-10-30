<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\Catalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Đọc/ghi quyền của vai trò. Mọi truy vấn chạy trong context Tenant hiện tại (RLS). */
class RoleService
{
    /**
     * Đồng bộ catalog trong config/rbac.php vào bảng permissions (chỉ thêm/cập nhật nhãn, không xóa).
     *
     * @return array<string, int> permission key => id
     */
    public function syncCatalog(): array
    {
        $now = now();
        $rows = [];

        foreach (Catalog::permissions() as $key => $info) {
            $rows[] = [
                'key' => $key, 'resource' => $info['resource'], 'action' => $info['action'],
                'label' => $info['label'], 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        Permission::upsert($rows, ['key'], ['resource', 'action', 'label', 'updated_at']);

        return Permission::pluck('id', 'key')->all();
    }

    /** @return array<string, string> permission key => scope */
    public function grants(Role $role): array
    {
        return DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $role->id)
            ->pluck('rp.scope', 'p.key')
            ->all();
    }

    /**
     * Thay toàn bộ quyền của vai trò.
     *
     * @param  array<string, string>  $grants  permission key => scope
     */
    public function setGrants(Role $role, array $grants): void
    {
        $ids = Permission::pluck('id', 'key');
        $now = now();
        $rows = [];

        foreach ($grants as $key => $scope) {
            if (! isset($ids[$key]) || ! isset(Catalog::SCOPES[$scope])) {
                throw new InvalidArgumentException("Quyền không hợp lệ: {$key}:{$scope}");
            }

            $rows[] = [
                'tenant_id' => $role->tenant_id, 'role_id' => $role->id, 'permission_id' => $ids[$key],
                'scope' => $scope, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($role, $rows) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        });
    }
}
