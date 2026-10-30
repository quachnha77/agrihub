<?php

namespace Tests\Feature\Rbac;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UserRoleAssignmentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function assignRoles(User $actor, User $target, array $roleUuids)
    {
        return $this->spa()->actingAs($actor)->putJson('/api/v1/users/'.$target->uuid.'/roles', ['role_ids' => $roleUuids]);
    }

    public function test_owner_assigns_roles_to_a_user(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, []);

        $response = $this->assignRoles($owner, $staff, [$this->roleOf($tenant, 'sales')->uuid, $this->roleOf($tenant, 'warehouse')->uuid])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['sales', 'warehouse'], collect($response->json('data.roles'))->pluck('key')->all());
    }

    public function test_assignment_replaces_previous_roles(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);

        $response = $this->assignRoles($owner, $staff, [$this->roleOf($tenant, 'accountant')->uuid])->assertOk();

        $this->assertSame(['accountant'], collect($response->json('data.roles'))->pluck('key')->all());
    }

    public function test_admin_cannot_grant_owner_role_to_anyone(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        $staff = $this->makeUserIn($tenant, []);

        $this->assignRoles($admin, $staff, [$this->roleOf($tenant, 'owner')->uuid])
            ->assertForbidden()->assertJsonPath('code', 'role_exceeds_privileges');
    }

    public function test_admin_cannot_promote_themselves(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->assignRoles($admin, $admin, [$this->roleOf($tenant, 'owner')->uuid])->assertForbidden();
    }

    public function test_admin_cannot_strip_an_owner_of_their_role(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->assignRoles($admin, $owner, [])->assertForbidden()->assertJsonPath('code', 'role_exceeds_privileges');
        $this->assertTrue($this->platform(fn () => $owner->roles()->where('key', 'owner')->exists()), 'Owner vẫn giữ vai trò');
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $this->assignRoles($owner, $owner, [$this->roleOf($tenant, 'admin')->uuid])
            ->assertStatus(422)->assertJsonPath('code', 'last_owner');
    }

    public function test_an_owner_can_be_demoted_when_another_active_owner_exists(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $this->makeUserIn($tenant, ['owner']);

        $this->assignRoles($owner, $owner, [$this->roleOf($tenant, 'admin')->uuid])->assertOk();
    }

    public function test_a_disabled_owner_does_not_count_as_another_owner(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $this->makeUserIn($tenant, ['owner'], attributes: ['status' => User::STATUS_DISABLED]);

        $this->assignRoles($owner, $owner, [$this->roleOf($tenant, 'admin')->uuid])->assertStatus(422)->assertJsonPath('code', 'last_owner');
    }

    public function test_cannot_assign_roles_from_another_tenant(): void
    {
        [$tenantA, $ownerA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $staff = $this->makeUserIn($tenantA, []);

        $this->assignRoles($ownerA, $staff, [$this->roleOf($tenantB, 'owner')->uuid])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_cannot_assign_roles_to_a_user_of_another_tenant(): void
    {
        [$tenantA, $ownerA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $foreign = $this->makeUserIn($tenantB, []);

        $this->assignRoles($ownerA, $foreign, [$this->roleOf($tenantA, 'sales')->uuid])->assertNotFound();
        $this->spa()->actingAs($ownerA)->getJson('/api/v1/users/'.$foreign->uuid)->assertNotFound();
    }

    public function test_user_without_update_permission_cannot_assign_roles(): void
    {
        [$tenant] = $this->makeTenant();
        $sales = $this->makeUserIn($tenant, ['sales']);
        $staff = $this->makeUserIn($tenant, []);

        $this->assignRoles($sales, $staff, [$this->roleOf($tenant, 'sales')->uuid])->assertForbidden();
    }

    public function test_role_ids_must_be_valid_uuids(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, []);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/roles', ['role_ids' => ['khong-phai-uuid']])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['role_ids.0']]);
        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/roles', [])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['role_ids']]);
    }

    public function test_branch_scoped_manager_only_sees_users_sharing_a_branch(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $other = Branch::factory()->make(['tenant_id' => $tenant->id]);
        $otherBranch = $this->platform(fn () => tap($other)->save());
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);
        $colleague = $this->makeUserIn($tenant, ['sales'], $branch);
        $stranger = $this->makeUserIn($tenant, ['sales'], $otherBranch);

        $ids = collect($this->spa()->actingAs($manager)->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($manager->uuid));
        $this->assertTrue($ids->contains($colleague->uuid));
        $this->assertTrue($ids->contains($owner->uuid)); // owner cũng thuộc chi nhánh này
        $this->assertFalse($ids->contains($stranger->uuid));
        $this->spa()->actingAs($manager)->getJson('/api/v1/users/'.$stranger->uuid)->assertForbidden();
    }

    public function test_user_list_is_limited_to_own_tenant(): void
    {
        [, $ownerA] = $this->makeTenant();
        [, $ownerB] = $this->makeTenant();

        $ids = collect($this->spa()->actingAs($ownerA)->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ownerA->uuid));
        $this->assertFalse($ids->contains($ownerB->uuid));
    }

    public function test_role_without_user_view_permission_cannot_list_users(): void
    {
        [$tenant] = $this->makeTenant();
        $delivery = $this->makeUserIn($tenant, ['delivery']);

        $this->spa()->actingAs($delivery)->getJson('/api/v1/users')->assertForbidden();
    }
}
