<?php

use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quyền mới `inventory.warehouse.*` (quản lý kho) cho các doanh nghiệp ĐÃ CÓ từ trước: `agrihub:sync-permissions` chỉ tự cấp mọi quyền mới cho Owner
 * và không ghi đè vai trò đã có (có thể đã được tùy chỉnh), nên các vai trò hệ thống khác sẽ không thấy kho. Vì quyền này CHƯA TỪNG tồn tại nên không
 * thể đã bị tùy chỉnh: cấp quyền mặc định (config/rbac.php) cho mỗi vai trò hệ thống chưa có dòng nào của quyền này. Chạy lại không làm gì thêm.
 * Doanh nghiệp mới được tạo sau này đã có sẵn quyền này từ RoleProvisioner.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RoleService::class)->syncCatalog(); // bảo đảm quyền mới đã có trong bảng permissions

        // Chế độ nền tảng để đọc/ghi `role_permissions` (RLS FORCE) của mọi doanh nghiệp.
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            $ids = DB::table('permissions')->where('key', 'like', 'inventory.warehouse.%')->pluck('id', 'key');
            $now = now();

            foreach (DB::table('roles')->where('is_system', true)->get(['id', 'tenant_id', 'key']) as $role) {
                if (DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_id', $ids->values())->exists()) {
                    continue;
                }

                $rows = [];

                foreach (Catalog::roleGrants($role->key) as $key => $scope) {
                    if (isset($ids[$key])) {
                        $rows[] = ['tenant_id' => $role->tenant_id, 'role_id' => $role->id, 'permission_id' => $ids[$key], 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now];
                    }
                }

                if ($rows !== []) {
                    DB::table('role_permissions')->insert($rows);
                }
            }
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }
    }

    /** Không thu hồi: quyền đã cấp có thể đã được Tenant chỉnh sau đó. */
    public function down(): void {}
};
