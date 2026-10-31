<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\MakesCatalog;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Khách hàng: dùng chung toàn doanh nghiệp, mỗi khách có chi nhánh phụ trách; hạn mức công nợ, địa chỉ, người liên hệ. */
class CustomerTest extends TestCase
{
    use CreatesTenants, MakesCatalog, ReadsAuditLog, RefreshDatabase;

    private function api(object $user)
    {
        return $this->spa()->actingAs($user);
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(object $branch, array $extra = []): array
    {
        return array_merge(['branch_id' => $branch->uuid, 'type' => 'individual', 'name' => 'Nguyễn Văn Nông'], $extra);
    }

    // --- tạo ----------------------------------------------------------------------------------

    public function test_the_owner_creates_a_customer_with_credit_terms_addresses_and_contacts(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $group = $this->platform(function () use ($tenant) {
            $g = new CustomerGroup(['name' => 'Khách quen']);
            $g->tenant_id = $tenant->id;
            $g->save();

            return $g;
        });

        $response = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, [
            'phone' => '0912 345 678', 'email' => 'nong@example.com', 'tax_code' => '0101234567', 'group_id' => $group->uuid,
            'credit_limit' => 5000000, 'credit_days' => 30, 'notes' => 'Trồng lúa 5 ha',
            'addresses' => [
                ['label' => 'Vườn', 'address' => 'Ấp 3, xã A', 'district' => 'Huyện B', 'province' => 'Cần Thơ'],
                ['label' => 'Nhà', 'address' => '12 đường C', 'is_default' => true],
            ],
            'contacts' => [['name' => 'Nguyễn Thị Vợ', 'phone' => '0987 654 321', 'position' => 'Vợ']],
        ]))->assertCreated();

        $response->assertJsonPath('data.code', 'KH0001')->assertJsonPath('data.type', 'individual')->assertJsonPath('data.type_label', 'Cá nhân / nông hộ')
            ->assertJsonPath('data.phone', '0912345678')->assertJsonPath('data.credit_limit', '5000000.00')->assertJsonPath('data.credit_days', 30)
            ->assertJsonPath('data.branch.id', $branch->uuid)->assertJsonPath('data.group.name', 'Khách quen')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.contacts.0.phone', '0987654321');

        // Địa chỉ mặc định đứng đầu.
        $this->assertSame(['Nhà', 'Vườn'], array_column($response->json('data.addresses'), 'label'));
        $this->assertSame([true, false], array_column($response->json('data.addresses'), 'is_default'));
        $this->assertSame($tenant->id, $this->platform(fn () => Customer::firstOrFail()->tenant_id), 'tenant_id lấy từ context, không từ request');

        $this->assertSame($branch->id, $this->lastAudit(['module' => 'customer', 'action' => 'created'])->branch_id, 'audit gắn chi nhánh phụ trách');
        $this->assertNotNull($this->lastAudit(['module' => 'customer', 'action' => 'addresses_changed']));
        $this->assertNotNull($this->lastAudit(['module' => 'customer', 'action' => 'contacts_changed']));
    }

    public function test_codes_are_generated_per_business_and_never_reused_after_a_delete(): void
    {
        [, $ownerA, $branchA] = $this->makeTenant();
        [, $ownerB, $branchB] = $this->makeTenant();

        $first = $this->api($ownerA)->postJson('/api/v1/customers', $this->payload($branchA))->assertCreated()->assertJsonPath('data.code', 'KH0001')->json('data.id');
        $this->api($ownerA)->postJson('/api/v1/customers', $this->payload($branchA, ['name' => 'Hai']))->assertCreated()->assertJsonPath('data.code', 'KH0002');
        $this->api($ownerA)->deleteJson("/api/v1/customers/{$first}")->assertNoContent();
        $this->api($ownerA)->postJson('/api/v1/customers', $this->payload($branchA, ['name' => 'Ba']))->assertCreated()->assertJsonPath('data.code', 'KH0003');

        // Doanh nghiệp khác đếm riêng.
        $this->api($ownerB)->postJson('/api/v1/customers', $this->payload($branchB))->assertCreated()->assertJsonPath('data.code', 'KH0001');
    }

    public function test_a_typed_code_is_unique_ignoring_case_and_is_released_by_deleting(): void
    {
        [, $owner, $branch] = $this->makeTenant();

        $id = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['code' => 'Dai-Ly-01']))->assertCreated()->json('data.id');
        $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['code' => 'DAI-LY-01']))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['code' => 'có dấu cách']))->assertStatus(422)->assertJsonValidationErrors('code');

        $this->api($owner)->deleteJson("/api/v1/customers/{$id}")->assertNoContent();
        $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['code' => 'dai-ly-01']))->assertCreated();
    }

    public function test_required_fields_and_formats_are_checked_with_vietnamese_messages(): void
    {
        [, $owner, $branch] = $this->makeTenant();
        $bad = fn (array $over) => $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, $over));

        $response = $this->api($owner)->postJson('/api/v1/customers', [])->assertStatus(422)->assertJsonValidationErrors(['branch_id', 'type', 'name']);
        $this->assertStringContainsStringIgnoringCase('tên khách hàng', $response->json('errors.name.0'));

        $bad(['type' => 'khong-co'])->assertStatus(422)->assertJsonValidationErrors('type');
        $bad(['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $bad(['email' => 'khong-phai-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $bad(['tax_code' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('tax_code');
        $bad(['credit_limit' => -1])->assertStatus(422)->assertJsonValidationErrors('credit_limit');
        $bad(['credit_limit' => 10.555])->assertStatus(422)->assertJsonValidationErrors('credit_limit');
        $bad(['credit_days' => 4000])->assertStatus(422)->assertJsonValidationErrors('credit_days');
        $bad(['name' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('name');
        $bad(['addresses' => [['label' => 'Nhà']]])->assertStatus(422)->assertJsonValidationErrors('addresses.0.address');
        $bad(['addresses' => [['address' => 'A', 'is_default' => true], ['address' => 'B', 'is_default' => true]]])->assertStatus(422)->assertJsonValidationErrors('addresses');
        $bad(['addresses' => array_fill(0, 11, ['address' => 'X'])])->assertStatus(422)->assertJsonValidationErrors('addresses');
        $bad(['contacts' => [['phone' => '0912345678']]])->assertStatus(422)->assertJsonValidationErrors('contacts.0.name');
        $bad(['contacts' => [['name' => 'A', 'phone' => '1']]])->assertStatus(422)->assertJsonValidationErrors('contacts.0.phone');
        $this->assertSame(0, $this->platform(fn () => Customer::count()));
    }

    public function test_the_first_address_becomes_the_default_when_none_is_marked_and_the_database_allows_only_one(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();

        $id = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['addresses' => [['address' => 'A'], ['address' => 'B']]]))->assertCreated()
            ->assertJsonPath('data.addresses.0.is_default', true)->assertJsonPath('data.addresses.1.is_default', false)->json('data.id');

        $customer = $this->platform(fn () => Customer::where('uuid', $id)->firstOrFail());
        $this->expectException(QueryException::class);
        $this->platform(fn () => DB::transaction(fn () => DB::table('customer_addresses')->insert(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'address' => 'C', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()])));
    }

    public function test_phone_is_not_unique_because_family_members_share_a_number(): void
    {
        [, $owner, $branch] = $this->makeTenant();

        $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['phone' => '0912345678']))->assertCreated();
        $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['name' => 'Con trai', 'phone' => '+84912345678']))->assertCreated();
    }

    // --- sửa ----------------------------------------------------------------------------------

    public function test_updating_changes_only_what_is_sent_and_audits_credit_changes(): void
    {
        [, $owner, $branch] = $this->makeTenant();
        $id = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, [
            'credit_limit' => 1000000, 'addresses' => [['address' => 'Giữ nguyên']], 'contacts' => [['name' => 'Liên hệ']],
        ]))->assertCreated()->json('data.id');

        $this->api($owner)->patchJson("/api/v1/customers/{$id}", ['credit_limit' => 8000000, 'credit_days' => 45, 'status' => 'inactive'])->assertOk()
            ->assertJsonPath('data.credit_limit', '8000000.00')->assertJsonPath('data.credit_days', 45)->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.name', 'Nguyễn Văn Nông')->assertJsonPath('data.addresses.0.address', 'Giữ nguyên')->assertJsonPath('data.contacts.0.name', 'Liên hệ');

        $audit = $this->lastAudit(['module' => 'customer', 'action' => 'updated']);
        $this->assertEquals(1000000, $audit->before['credit_limit']);
        $this->assertEquals(8000000, $audit->after['credit_limit']);

        // Gửi addresses/contacts là thay nguyên khối; gửi danh sách rỗng là xóa hết.
        $this->api($owner)->patchJson("/api/v1/customers/{$id}", ['addresses' => [['address' => 'Mới 1'], ['address' => 'Mới 2']], 'contacts' => []])->assertOk()
            ->assertJsonCount(2, 'data.addresses')->assertJsonCount(0, 'data.contacts')->assertJsonPath('data.addresses.0.is_default', true);
    }

    public function test_the_branch_and_type_can_change_and_a_branch_change_is_audited(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $id = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branchA))->assertCreated()->json('data.id');

        $this->api($owner)->patchJson("/api/v1/customers/{$id}", ['branch_id' => $branchB->uuid, 'type' => 'agent'])->assertOk()
            ->assertJsonPath('data.branch.id', $branchB->uuid)->assertJsonPath('data.type', 'agent')->assertJsonPath('data.type_label', 'Đại lý');

        $audit = $this->lastAudit(['module' => 'customer', 'action' => 'branch_changed']);
        $this->assertSame($branchA->id, $audit->before['branch_id']);
        $this->assertSame($branchB->id, $audit->after['branch_id']);
    }

    public function test_a_closed_branch_cannot_be_chosen(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $closed = $this->makeBranch($tenant, ['status' => 'inactive']);

        $this->api($owner)->postJson('/api/v1/customers', $this->payload($closed))->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    // --- tìm và lọc ---------------------------------------------------------------------------

    public function test_listing_searches_and_filters(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = $this->makeBranch($tenant);
        $mk = fn (array $over) => $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, $over))->assertCreated();
        $mk(['name' => 'Trần Văn An', 'phone' => '0912345678', 'code' => 'KH-AN']);
        $mk(['name' => 'Đại lý Bình', 'type' => 'agent', 'phone' => '0933111222']);
        $mk(['name' => 'HTX Cường', 'type' => 'cooperative', 'branch_id' => $other->uuid, 'status' => 'inactive']);

        $list = fn (string $q) => $this->api($owner)->getJson('/api/v1/customers?'.$q)->assertOk();

        $this->assertCount(3, $list('')->json('data'));
        $this->assertCount(1, $list('q=an')->json('data'), 'tìm theo tên, không phân biệt hoa thường');
        $this->assertCount(1, $list('q=KH-AN')->json('data'), 'tìm theo mã');
        $this->assertCount(1, $list('q='.urlencode('0912 345'))->json('data'), 'tìm theo số điện thoại gõ kiểu nào cũng được');
        $this->assertCount(1, $list('type=agent')->json('data'));
        $this->assertCount(2, $list('branch='.$branch->uuid)->json('data'));
        $this->assertCount(1, $list('status=inactive')->json('data'));
        $this->assertCount(2, $list('per_page=2')->json('data'));
        $this->api($owner)->getJson('/api/v1/customers?type=khong-co')->assertStatus(422);
    }

    // --- phạm vi theo chi nhánh -----------------------------------------------------------------

    public function test_a_branch_manager_works_only_with_customers_of_their_own_branches(): void
    {
        [$tenant, $owner, $branchA] = $this->makeTenant();
        $branchB = $this->makeBranch($tenant);
        $branchC = $this->makeBranch($tenant);
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branchA);
        $this->platform(fn () => $manager->branches()->attach($branchC->id, ['tenant_id' => $tenant->id]));
        $inA = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branchA, ['name' => 'Ở A']))->assertCreated()->json('data.id');
        $inB = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branchB, ['name' => 'Ở B']))->assertCreated()->json('data.id');

        $this->assertSame([$inA], array_column($this->api($manager)->getJson('/api/v1/customers')->assertOk()->json('data'), 'id'));
        $this->assertCount(0, $this->api($manager)->getJson('/api/v1/customers?branch='.$branchB->uuid)->json('data'));
        $this->api($manager)->getJson("/api/v1/customers/{$inB}")->assertForbidden();

        $this->api($manager)->postJson('/api/v1/customers', $this->payload($branchA, ['name' => 'Mới ở A']))->assertCreated();
        $this->api($manager)->postJson('/api/v1/customers', $this->payload($branchB, ['name' => 'Mới ở B']))->assertForbidden();
        $this->api($manager)->patchJson("/api/v1/customers/{$inA}", ['credit_limit' => 1000000])->assertOk();
        $this->api($manager)->patchJson("/api/v1/customers/{$inB}", ['credit_limit' => 1])->assertForbidden();

        // Chuyển giữa hai chi nhánh của mình được; "đẩy" khách ra chi nhánh ngoài phạm vi thì không.
        $this->api($manager)->patchJson("/api/v1/customers/{$inA}", ['branch_id' => $branchC->uuid])->assertOk()->assertJsonPath('data.branch.id', $branchC->uuid);
        $this->api($manager)->patchJson("/api/v1/customers/{$inA}", ['branch_id' => $branchB->uuid])->assertForbidden();

        // Quản lý chi nhánh không có quyền xóa khách.
        $this->api($manager)->deleteJson("/api/v1/customers/{$inA}")->assertForbidden();
    }

    public function test_sales_staff_can_add_and_edit_in_their_branch_and_the_accountant_can_only_view(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $accountant = $this->makeUserIn($tenant, ['accountant'], $branch);
        $id = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch))->assertCreated()->json('data.id');

        $this->api($sales)->postJson('/api/v1/customers', $this->payload($branch, ['name' => 'Khách của nhân viên']))->assertCreated();
        $this->api($sales)->patchJson("/api/v1/customers/{$id}", ['notes' => 'Đã gọi'])->assertOk();
        $this->api($sales)->deleteJson("/api/v1/customers/{$id}")->assertForbidden();

        $this->api($accountant)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data');
        $this->api($accountant)->postJson('/api/v1/customers', $this->payload($branch))->assertForbidden();
        $this->api($accountant)->patchJson("/api/v1/customers/{$id}", ['notes' => 'x'])->assertForbidden();
    }

    public function test_roles_without_customer_access_get_403_even_for_an_empty_form(): void
    {
        [$tenant, , $branch] = $this->makeTenant();

        foreach (['warehouse', 'delivery'] as $role) {
            $user = $this->makeUserIn($tenant, [$role], $branch);

            $this->api($user)->getJson('/api/v1/customers')->assertForbidden();
            $this->api($user)->postJson('/api/v1/customers', [])->assertForbidden(); // 403 đến trước kiểm tra dữ liệu
        }
    }

    // --- cô lập giữa các doanh nghiệp ----------------------------------------------------------------

    public function test_one_business_never_sees_or_touches_anothers_customers(): void
    {
        [, $ownerA, $branchA] = $this->makeTenant();
        [$tenantB, $ownerB, $branchB] = $this->makeTenant();
        $idB = $this->api($ownerB)->postJson('/api/v1/customers', $this->payload($branchB))->assertCreated()->json('data.id');
        $groupB = $this->platform(function () use ($tenantB) {
            $g = new CustomerGroup(['name' => 'Của B']);
            $g->tenant_id = $tenantB->id;
            $g->save();

            return $g;
        });

        $this->assertCount(0, $this->api($ownerA)->getJson('/api/v1/customers')->json('data'));
        $this->api($ownerA)->getJson("/api/v1/customers/{$idB}")->assertNotFound();
        $this->api($ownerA)->patchJson("/api/v1/customers/{$idB}", ['name' => 'x'])->assertNotFound();
        $this->api($ownerA)->deleteJson("/api/v1/customers/{$idB}")->assertNotFound();

        $this->api($ownerA)->postJson('/api/v1/customers', $this->payload($branchB))->assertStatus(422)->assertJsonValidationErrors('branch_id');
        $this->api($ownerA)->postJson('/api/v1/customers', $this->payload($branchA, ['group_id' => $groupB->uuid]))->assertStatus(422)->assertJsonValidationErrors('group_id');
    }

    public function test_row_level_security_hides_other_tenants_customers_even_from_raw_queries(): void
    {
        [$tenantA, , $branchA] = $this->makeTenant();
        [, $ownerB, $branchB] = $this->makeTenant();
        $this->api($ownerB)->postJson('/api/v1/customers', $this->payload($branchB))->assertCreated();
        $this->platform(fn () => DB::table('customers')->insert(['tenant_id' => $tenantA->id, 'branch_id' => $branchA->id, 'code' => 'RAW', 'type' => 'individual', 'name' => 'Raw', 'created_at' => now(), 'updated_at' => now()]));

        $seen = app(TenantContext::class)->runForTenant($tenantA->id, fn () => [DB::table('customers')->count(), DB::table('customer_addresses')->count(), DB::table('customer_contacts')->count()]);

        $this->assertSame([1, 0, 0], $seen);
    }

    public function test_ids_that_are_not_uuids_are_404_not_500(): void
    {
        [, $owner] = $this->makeTenant();

        $this->api($owner)->getJson('/api/v1/customers/khong-phai-uuid')->assertNotFound();
        $this->api($owner)->deleteJson('/api/v1/customer-groups/123')->assertNotFound();
    }

    // --- nhóm khách hàng --------------------------------------------------------------------------

    public function test_customer_groups_are_shared_unique_by_name_and_protected_while_in_use(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);

        $id = $this->api($owner)->postJson('/api/v1/customer-groups', ['name' => 'Dai ly'])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->api($owner)->postJson('/api/v1/customer-groups', ['name' => 'DAI LY'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->api($owner)->patchJson("/api/v1/customer-groups/{$id}", ['name' => 'Đại lý cấp 1'])->assertOk();

        // Quản lý chi nhánh thêm được nhưng không đổi tên / xóa được.
        $this->api($manager)->postJson('/api/v1/customer-groups', ['name' => 'Khách quen'])->assertCreated();
        $this->api($manager)->patchJson("/api/v1/customer-groups/{$id}", ['name' => 'x'])->assertForbidden();
        $this->api($manager)->deleteJson("/api/v1/customer-groups/{$id}")->assertForbidden();

        $customer = $this->api($owner)->postJson('/api/v1/customers', $this->payload($branch, ['group_id' => $id]))->assertCreated()->json('data.id');
        $this->api($owner)->deleteJson("/api/v1/customer-groups/{$id}")->assertStatus(409)->assertJsonPath('code', 'catalog_item_in_use');
        $this->api($owner)->getJson('/api/v1/customers?group='.$id)->assertOk()->assertJsonCount(1, 'data');

        $this->api($owner)->deleteJson("/api/v1/customers/{$customer}")->assertNoContent();
        $this->api($owner)->deleteJson("/api/v1/customer-groups/{$id}")->assertNoContent();
    }
}
