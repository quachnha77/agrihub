<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Khách hàng và nhà cung cấp nằm trong sao lưu / khôi phục theo doanh nghiệp (dùng danh sách bảng THẬT của config/backup.php). */
class CrmBackupTest extends TestCase
{
    use AdministersBackups, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    public function test_the_tables_are_registered_for_backup_with_parents_before_children(): void
    {
        $tables = config('backup.tenant_tables');
        $pos = fn (string $t) => array_search($t, $tables, true);

        foreach (['customer_groups', 'suppliers', 'supplier_contacts', 'customers', 'customer_addresses', 'customer_contacts'] as $table) {
            $this->assertNotFalse($pos($table), "{$table} chưa nằm trong backup.tenant_tables");
            $this->assertTrue(Schema::hasColumns($table, ['id', 'tenant_id']));
        }

        $this->assertLessThan($pos('supplier_contacts'), $pos('suppliers'));
        $this->assertLessThan($pos('customers'), $pos('customer_groups'));
        $this->assertLessThan($pos('customer_addresses'), $pos('customers'));
        $this->assertLessThan($pos('customer_contacts'), $pos('customers'));
    }

    public function test_restoring_brings_back_customers_and_suppliers_exactly(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        [, $otherOwner, $otherBranch] = $this->makeTenant();
        $api = fn ($user) => $this->freshBrowser()->spa()->actingAs($user);

        $group = $api($owner)->postJson('/api/v1/customer-groups', ['name' => 'Đại lý'])->assertCreated()->json('data.id');
        $keep = $api($owner)->postJson('/api/v1/customers', [
            'branch_id' => $branch->uuid, 'type' => 'agent', 'name' => 'Đại lý Bình', 'phone' => '0933111222', 'group_id' => $group, 'credit_limit' => 12500000.5, 'credit_days' => 45,
            'addresses' => [['label' => 'Kho', 'address' => '1 Quốc lộ', 'province' => 'Cần Thơ', 'is_default' => true], ['address' => 'Cửa hàng 2']],
            'contacts' => [['name' => 'Chị Lan', 'phone' => '0987654321', 'position' => 'Kế toán']],
        ])->assertCreated()->json('data');
        $gone = $api($owner)->postJson('/api/v1/customers', ['branch_id' => $branch->uuid, 'type' => 'individual', 'name' => 'Sẽ bị xóa'])->assertCreated()->json('data.id');
        $supplier = $api($owner)->postJson('/api/v1/suppliers', ['name' => 'NCC Giữ', 'payment_terms_days' => 30, 'contacts' => [['name' => 'Anh Hùng', 'email' => 'h@x.vn']]])->assertCreated()->json('data');
        $theirs = $api($otherOwner)->postJson('/api/v1/customers', ['branch_id' => $otherBranch->uuid, 'type' => 'individual', 'name' => 'Khách của B'])->assertCreated()->json('data.id');

        $backup = $this->adminBackup($tenant);
        $this->assertEqualsCanonicalizing(
            ['customer_groups', 'customers', 'customer_addresses', 'customer_contacts', 'suppliers', 'supplier_contacts'],
            array_values(array_intersect(array_keys(array_filter($backup->row_counts, fn ($n) => $n > 0)), ['customer_groups', 'customers', 'customer_addresses', 'customer_contacts', 'suppliers', 'supplier_contacts'])),
        );

        // Sau khi sao lưu: đổi hạn mức, xóa một khách, xóa nhà cung cấp, thêm khách mới.
        $api($owner)->patchJson("/api/v1/customers/{$keep['id']}", ['credit_limit' => 1, 'addresses' => [['address' => 'Đã đổi']]])->assertOk();
        $api($owner)->deleteJson("/api/v1/customers/{$gone}")->assertNoContent();
        $api($owner)->deleteJson("/api/v1/suppliers/{$supplier['id']}")->assertNoContent();
        $api($owner)->postJson('/api/v1/customers', ['branch_id' => $branch->uuid, 'type' => 'individual', 'name' => 'Mới thêm sau sao lưu'])->assertCreated();

        $this->adminRestore($backup, 'Cửa hàng Nông Phát')->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $restored = $api($owner)->getJson('/api/v1/customers?per_page=50')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Đại lý Bình', 'Sẽ bị xóa'], array_column($restored, 'name'));
        $back = collect($restored)->firstWhere('name', 'Đại lý Bình');
        $this->assertSame($keep['id'], $back['id'], 'mã công khai (uuid) được giữ nguyên');
        $this->assertSame($keep['code'], $back['code']);
        $this->assertSame('12500000.50', $back['credit_limit']);
        $this->assertSame(45, $back['credit_days']);
        $this->assertSame('Đại lý', $back['group']['name']);
        $this->assertSame(['Kho', null], array_column($back['addresses'], 'label'));
        $this->assertSame([true, false], array_column($back['addresses'], 'is_default'));
        $this->assertSame('Chị Lan', $back['contacts'][0]['name']);

        $suppliers = $api($owner)->getJson('/api/v1/suppliers')->assertOk()->json('data');
        $this->assertSame(['NCC Giữ'], array_column($suppliers, 'name'));
        $this->assertSame('Anh Hùng', $suppliers[0]['contacts'][0]['name']);
        $this->assertSame(30, $suppliers[0]['payment_terms_days']);

        // Doanh nghiệp khác không bị ảnh hưởng.
        $this->assertSame([$theirs], array_column($api($otherOwner)->getJson('/api/v1/customers')->json('data'), 'id'));
        $this->assertSame(2, $this->platform(fn () => Customer::where('tenant_id', $tenant->id)->count()));
        $this->assertSame(1, $this->platform(fn () => Supplier::where('tenant_id', $tenant->id)->count()));
    }
}
