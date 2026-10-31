<?php

namespace Tests\Feature\Crm;

use App\Models\Supplier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Nhà cung cấp: dùng chung toàn doanh nghiệp; xem/thêm theo quyền, sửa/xóa cấp doanh nghiệp. */
class SupplierTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    public function test_the_owner_creates_a_supplier_with_terms_and_contacts(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $response = $this->api($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Công ty Phân bón Miền Nam', 'tax_code' => '0301234567', 'phone' => '028 3822 1234', 'email' => 'ban-hang@pbmn.vn', 'address' => '1 Đường A, TP.HCM',
            'payment_terms_days' => 30, 'notes' => 'Giao hàng thứ Ba',
            'contacts' => [['name' => 'Anh Hùng', 'phone' => '0912 345 678', 'email' => 'hung@pbmn.vn', 'position' => 'Kinh doanh']],
        ])->assertCreated();

        $response->assertJsonPath('data.code', 'NCC0001')->assertJsonPath('data.payment_terms_days', 30)->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.contacts.0.phone', '0912345678')->assertJsonPath('data.contacts.0.position', 'Kinh doanh');
        $this->assertSame($tenant->id, $this->platform(fn () => Supplier::firstOrFail()->tenant_id));
        $this->assertNotNull($this->lastAudit(['module' => 'supplier', 'action' => 'created']));
        $this->assertNotNull($this->lastAudit(['module' => 'supplier', 'action' => 'contacts_changed']));

        $this->api($owner)->postJson('/api/v1/suppliers', ['name' => 'Hai'])->assertCreated()->assertJsonPath('data.code', 'NCC0002');
    }

    public function test_validation_and_unique_codes(): void
    {
        [, $owner] = $this->makeTenant();
        $post = fn (array $body) => $this->api($owner)->postJson('/api/v1/suppliers', $body);

        $this->assertStringContainsStringIgnoringCase('tên nhà cung cấp', $post([])->assertStatus(422)->assertJsonValidationErrors('name')->json('errors.name.0'));
        $post(['name' => 'A', 'tax_code' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('tax_code');
        $post(['name' => 'A', 'phone' => '123'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $post(['name' => 'A', 'email' => 'x'])->assertStatus(422)->assertJsonValidationErrors('email');
        $post(['name' => 'A', 'payment_terms_days' => -1])->assertStatus(422)->assertJsonValidationErrors('payment_terms_days');
        $post(['name' => 'A', 'contacts' => [['phone' => '0912345678']]])->assertStatus(422)->assertJsonValidationErrors('contacts.0.name');

        $id = $post(['name' => 'Một', 'code' => 'Hang-A'])->assertCreated()->json('data.id');
        $post(['name' => 'Hai', 'code' => 'HANG-A'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->api($owner)->deleteJson("/api/v1/suppliers/{$id}")->assertNoContent();
        $post(['name' => 'Ba', 'code' => 'hang-a'])->assertCreated();
        // Mã tự sinh chỉ tính các mã dạng NCC####; mã gõ tay (Hang-A) không làm lệch dãy số.
        $first = $post(['name' => 'Bốn'])->assertCreated()->json('data.code');
        $this->assertSame('NCC0001', $first);
    }

    public function test_updating_replaces_contacts_as_a_whole_and_keeps_other_fields(): void
    {
        [, $owner] = $this->makeTenant();
        $id = $this->api($owner)->postJson('/api/v1/suppliers', ['name' => 'NCC', 'payment_terms_days' => 15, 'contacts' => [['name' => 'Cũ']]])->assertCreated()->json('data.id');

        $this->api($owner)->patchJson("/api/v1/suppliers/{$id}", ['status' => 'inactive'])->assertOk()
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.payment_terms_days', 15)->assertJsonPath('data.contacts.0.name', 'Cũ');
        $this->api($owner)->patchJson("/api/v1/suppliers/{$id}", ['contacts' => [['name' => 'Mới 1'], ['name' => 'Mới 2']]])->assertOk()->assertJsonCount(2, 'data.contacts');

        $audit = $this->lastAudit(['module' => 'supplier', 'action' => 'contacts_changed']);
        $this->assertSame('Cũ', $audit->before['contacts'][0]['name']);
        $this->assertSame(['Mới 1', 'Mới 2'], array_column($audit->after['contacts'], 'name'));
    }

    public function test_listing_searches_and_filters(): void
    {
        [, $owner] = $this->makeTenant();
        $mk = fn (array $body) => $this->api($owner)->postJson('/api/v1/suppliers', $body)->assertCreated();
        $mk(['name' => 'Bayer Việt Nam', 'phone' => '0281112222', 'tax_code' => '0300000001']);
        $mk(['name' => 'Syngenta', 'tax_code' => '0300000002', 'status' => 'inactive']);

        $list = fn (string $q) => $this->api($owner)->getJson('/api/v1/suppliers?'.$q)->assertOk()->json('data');

        $this->assertCount(2, $list(''));
        $this->assertCount(1, $list('q=bayer'));
        $this->assertCount(1, $list('q=0300000002'));
        $this->assertCount(1, $list('q='.urlencode('028 1112')));
        $this->assertCount(1, $list('status=inactive'));
        $this->api($owner)->getJson('/api/v1/suppliers?status=khong-co')->assertStatus(422);
    }

    public function test_everyone_who_can_view_sees_all_suppliers_but_only_the_business_level_can_edit_or_delete(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeUserIn($tenant, ['admin'], null);
        $id = $this->api($owner)->postJson('/api/v1/suppliers', ['name' => 'Dùng chung'])->assertCreated()->json('data.id');

        // Kho và kế toán xem được; quản lý chi nhánh xem và thêm được nhưng không sửa/xóa (ảnh hưởng mọi chi nhánh).
        foreach (['warehouse', 'accountant', 'branch_manager'] as $role) {
            $user = $this->makeUserIn($tenant, [$role], $branch);
            $this->api($user)->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data');
            $this->api($user)->getJson("/api/v1/suppliers/{$id}")->assertOk();
            $this->api($user)->patchJson("/api/v1/suppliers/{$id}", ['name' => 'x'])->assertForbidden();
            $this->api($user)->deleteJson("/api/v1/suppliers/{$id}")->assertForbidden();
        }

        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);
        $this->api($manager)->postJson('/api/v1/suppliers', ['name' => 'Do quản lý thêm'])->assertCreated();
        $warehouse = $this->makeUserIn($tenant, ['warehouse'], $branch);
        $this->api($warehouse)->postJson('/api/v1/suppliers', ['name' => 'x'])->assertForbidden();

        // Admin có đủ quyền cấp doanh nghiệp.
        $this->api($other)->patchJson("/api/v1/suppliers/{$id}", ['name' => 'Admin sửa'])->assertOk();
    }

    public function test_roles_without_supplier_access_get_403_even_for_an_empty_form(): void
    {
        [$tenant, , $branch] = $this->makeTenant();

        foreach (['sales', 'delivery'] as $role) {
            $user = $this->makeUserIn($tenant, [$role], $branch);

            $this->api($user)->getJson('/api/v1/suppliers')->assertForbidden();
            $this->api($user)->postJson('/api/v1/suppliers', [])->assertForbidden();
        }
    }

    public function test_one_business_never_sees_or_touches_anothers_suppliers(): void
    {
        [, $ownerA] = $this->makeTenant();
        [$tenantB, $ownerB] = $this->makeTenant();
        $idB = $this->api($ownerB)->postJson('/api/v1/suppliers', ['name' => 'Của B'])->assertCreated()->json('data.id');

        $this->assertCount(0, $this->api($ownerA)->getJson('/api/v1/suppliers')->json('data'));
        $this->api($ownerA)->getJson("/api/v1/suppliers/{$idB}")->assertNotFound();
        $this->api($ownerA)->patchJson("/api/v1/suppliers/{$idB}", ['name' => 'x'])->assertNotFound();
        $this->api($ownerA)->deleteJson("/api/v1/suppliers/{$idB}")->assertNotFound();

        $seen = app(TenantContext::class)->runForTenant($tenantB->id, fn () => [DB::table('suppliers')->count(), DB::table('supplier_contacts')->count()]);
        $this->assertSame([1, 0], $seen);
        $this->assertSame(0, app(TenantContext::class)->runForTenant($tenantB->id, fn () => DB::table('suppliers')->where('tenant_id', '!=', $tenantB->id)->count()));
    }
}
