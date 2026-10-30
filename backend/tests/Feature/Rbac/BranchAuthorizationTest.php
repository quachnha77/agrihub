<?php

namespace Tests\Feature\Rbac;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BranchAuthorizationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_user_without_any_role_is_forbidden(): void
    {
        [, $user] = $this->makeTenant(roles: []);

        $this->spa()->actingAs($user)->getJson('/api/v1/branches')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_role_without_branch_permission_is_forbidden(): void
    {
        [, $user] = $this->makeTenant(roles: ['delivery']);

        $this->spa()->actingAs($user)->getJson('/api/v1/branches')->assertForbidden();
    }

    public function test_branch_scope_only_sees_assigned_branches(): void
    {
        [$tenant, , $assigned] = $this->makeTenant();
        $other = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id]));
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $assigned);

        $ids = collect($this->spa()->actingAs($manager)->getJson('/api/v1/branches')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$assigned->uuid], $ids->all());

        $this->spa()->actingAs($manager)->getJson('/api/v1/branches/'.$assigned->uuid)->assertOk();
        $this->spa()->actingAs($manager)->getJson('/api/v1/branches/'.$other->uuid)->assertForbidden();
    }

    public function test_tenant_scope_sees_all_branches_of_own_tenant_only(): void
    {
        [$tenant, $owner, $first] = $this->makeTenant();
        $second = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id]));
        [, , $foreign] = $this->makeTenant();

        $ids = collect($this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], $ids->all());
        $this->assertFalse($ids->contains($foreign->uuid));
    }

    public function test_branch_manager_cannot_create_update_or_delete_branches(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);

        $this->spa()->actingAs($manager)->postJson('/api/v1/branches', ['code' => 'MOI', 'name' => 'Mới'])->assertForbidden();
        $this->spa()->actingAs($manager)->patchJson('/api/v1/branches/'.$branch->uuid, ['name' => 'Đổi tên'])->assertForbidden();
        $this->spa()->actingAs($manager)->deleteJson('/api/v1/branches/'.$branch->uuid)->assertForbidden();
    }

    public function test_admin_can_create_and_update_but_not_delete_branches(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $created = $this->spa()->actingAs($admin)
            ->postJson('/api/v1/branches', ['code' => 'HN-01', 'name' => 'Hà Nội', 'tenant_id' => 999999])
            ->assertCreated()->assertJsonPath('data.code', 'HN-01')->assertJsonPath('data.status', 'active');

        $uuid = $created->json('data.id');
        $this->assertSame($tenant->id, $this->platform(fn () => Branch::where('uuid', $uuid)->value('tenant_id')));

        $this->spa()->actingAs($admin)->patchJson('/api/v1/branches/'.$uuid, ['name' => 'Hà Nội 2'])
            ->assertOk()->assertJsonPath('data.name', 'Hà Nội 2');

        $this->spa()->actingAs($admin)->deleteJson('/api/v1/branches/'.$uuid)->assertForbidden();
    }

    public function test_owner_can_delete_unused_branch_but_not_one_with_users(): void
    {
        [$tenant, $owner, $usedBranch] = $this->makeTenant();
        $empty = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id]));

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/branches/'.$empty->uuid)->assertNoContent();
        $this->spa()->actingAs($owner)->deleteJson('/api/v1/branches/'.$usedBranch->uuid)
            ->assertStatus(409)->assertJsonPath('code', 'branch_in_use');
    }

    public function test_branch_code_must_be_unique_within_tenant_but_can_repeat_across_tenants(): void
    {
        [$tenantA, $ownerA] = $this->makeTenant();
        [, $ownerB] = $this->makeTenant();

        $this->spa()->actingAs($ownerA)->postJson('/api/v1/branches', ['code' => 'DUP', 'name' => 'A'])->assertCreated();
        $this->spa()->actingAs($ownerA)->postJson('/api/v1/branches', ['code' => 'DUP', 'name' => 'A2'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);
        $this->spa()->actingAs($ownerB)->postJson('/api/v1/branches', ['code' => 'DUP', 'name' => 'B'])->assertCreated();
    }

    public function test_cannot_modify_another_tenants_branch(): void
    {
        [, $ownerA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();

        $this->spa()->actingAs($ownerA)->patchJson('/api/v1/branches/'.$branchB->uuid, ['name' => 'Chiếm quyền'])->assertNotFound();
        $this->spa()->actingAs($ownerA)->deleteJson('/api/v1/branches/'.$branchB->uuid)->assertNotFound();
        $this->assertNotSame('Chiếm quyền', $this->platform(fn () => Branch::find($branchB->id)->name));
    }

    public function test_branch_validation(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'có dấu cách', 'name' => ''])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['code', 'name']]);
    }
}
