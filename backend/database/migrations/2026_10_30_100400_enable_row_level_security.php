<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security: lớp phòng thủ thứ 3 (xem docs/MULTI_TENANT_DESIGN.md).
 *
 * Context lấy từ biến phiên `app.tenant_id` và `app.is_platform` do App\Support\Tenancy\TenantContext đặt.
 * Không có context => không thấy dòng nào (fail closed). FORCE để chính owner của bảng cũng bị áp dụng.
 */
return new class extends Migration
{
    /** @var array<string, string> bảng => cột chứa tenant id */
    private array $tables = [
        'tenants' => 'id',
        'branches' => 'tenant_id',
        'users' => 'tenant_id',
        'user_branches' => 'tenant_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $column) {
            $predicate = "({$column} = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
