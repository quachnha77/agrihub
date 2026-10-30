<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Dựng hai bảng nghiệp vụ giả (cha → con, có khóa ngoại và RLS) để kiểm thử sao lưu/khôi phục khi chưa có module thật.
 * DDL nằm trong transaction của RefreshDatabase nên tự biến mất sau mỗi test.
 */
trait UsesBackupTables
{
    protected function setUpBackupTables(): void
    {
        Storage::fake('backups');
        config(['backup.disk' => 'backups', 'backup.tenant_tables' => ['bk_customers', 'bk_orders']]);

        Schema::create('bk_customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->string('name');
            $t->jsonb('meta')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('bk_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->foreignId('customer_id')->constrained('bk_customers');
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });

        Schema::create('bk_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants');
            $t->string('body');
        });

        foreach (['bk_customers', 'bk_orders', 'bk_notes'] as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint OR current_setting('app.is_platform', true) = 'on')";
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    /** Thêm $customers khách, mỗi khách 2 đơn hàng. */
    protected function seedBusinessData(Tenant $tenant, int $customers = 2, string $prefix = 'Khách'): void
    {
        $this->platform(function () use ($tenant, $customers, $prefix) {
            for ($i = 1; $i <= $customers; $i++) {
                $id = DB::table('bk_customers')->insertGetId([
                    'tenant_id' => $tenant->id, 'name' => "{$prefix} {$i} Ư", 'meta' => json_encode(['vip' => $i === 1, 'ghi_chú' => 'Nợ cũ']),
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);

                foreach ([100000.5 * $i, 250000] as $total) {
                    DB::table('bk_orders')->insert(['tenant_id' => $tenant->id, 'customer_id' => $id, 'total' => $total, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    /** Trạng thái dữ liệu nghiệp vụ của một Tenant, để so sánh trước/sau. */
    protected function businessState(Tenant $tenant): array
    {
        return $this->platform(fn () => [
            'customers' => DB::table('bk_customers')->where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'name', 'meta', 'active'])->map(fn ($r) => (array) $r)->all(),
            'orders' => DB::table('bk_orders')->where('tenant_id', $tenant->id)->orderBy('id')->get(['id', 'customer_id', 'total'])->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    protected function wipeBusinessData(Tenant $tenant): void
    {
        $this->platform(function () use ($tenant) {
            DB::table('bk_orders')->where('tenant_id', $tenant->id)->delete();
            DB::table('bk_customers')->where('tenant_id', $tenant->id)->delete();
        });
    }
}
