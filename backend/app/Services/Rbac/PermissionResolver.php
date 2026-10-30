<?php

namespace App\Services\Rbac;

use App\Models\User;
use App\Support\Platform\SupportSession;
use App\Support\Rbac\Catalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class PermissionResolver
{
    public function __construct(private TenantContext $context, private SupportSession $support) {}

    /**
     * Quyền hiệu lực của user = hợp các vai trò, lấy scope rộng nhất cho mỗi quyền.
     *
     * Platform Admin không có quyền Tenant nào, TRỪ khi đang trong phiên hỗ trợ: khi đó chỉ có các quyền `view`
     * (không tạo/sửa/xóa/duyệt/xuất) ở phạm vi Tenant được hỗ trợ.
     *
     * @return array<string, string> permission key => scope
     */
    public function forUser(User $user): array
    {
        if ($user->is_platform_admin) {
            return $this->support->active() ? $this->supportReadOnlyPermissions() : [];
        }

        if ($user->tenant_id === null) {
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

    /** @return array<string, string> */
    private function supportReadOnlyPermissions(): array
    {
        $map = [];

        foreach (Catalog::permissions() as $key => $info) {
            if ($info['action'] === 'view') {
                $map[$key] = 'tenant';
            }
        }

        ksort($map);

        return $map;
    }
}
