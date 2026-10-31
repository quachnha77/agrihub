<?php

namespace Tests\Feature\Users;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function newUser(array $override = []): array
    {
        return $override + ['name' => 'Nhân viên mới', 'email' => 'nhanvien.moi@example.com', 'phone' => '0987654321'];
    }

    private function dbUser(string $email): ?User
    {
        return $this->platform(fn () => User::withTrashed()->where('email', $email)->first());
    }

    // --- Tạo ------------------------------------------------------------------------------

    public function test_owner_creates_a_user_with_roles_branches_and_a_one_time_temporary_password(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();

        $response = $this->spa()->actingAs($owner)->postJson('/api/v1/users', $this->newUser([
            'email' => 'NhanVien.Moi@Example.com',
            'role_ids' => [$this->roleOf($tenant, 'sales')->uuid],
            'branch_ids' => [$branch->uuid],
        ]))->assertCreated()
            ->assertJsonPath('data.email', 'nhanvien.moi@example.com')
            ->assertJsonPath('data.roles.0.key', 'sales')
            ->assertJsonPath('data.branches.0.id', $branch->uuid);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $temporary = $response->json('temporary_password');

        $user = $this->dbUser('nhanvien.moi@example.com');
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertFalse($user->is_platform_admin);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporary, $user->password));

        $created = $this->lastAudit(['module' => 'user', 'action' => 'created', 'subject_id' => $user->uuid]);
        $this->assertSame($owner->uuid, $created->actor_uuid);
        $this->assertSame('[REDACTED]', $created->after['password']);
        $assigned = $this->lastAudit(['action' => 'user_roles_changed', 'subject_id' => $user->uuid]);
        $this->assertSame(['sales'], $assigned->after['roles']);
        $this->assertStringNotContainsString($temporary, json_encode($this->auditRows()->all()));
    }

    public function test_a_supplied_password_is_used_and_not_echoed(): void
    {
        [, $owner] = $this->makeTenant();

        $response = $this->spa()->actingAs($owner)->postJson('/api/v1/users', $this->newUser(['password' => 'Mat-khau-rieng-2026']))
            ->assertCreated()->assertJsonMissingPath('temporary_password');

        $this->assertStringNotContainsString('Mat-khau-rieng-2026', $response->getContent());
        $this->assertTrue(Hash::check('Mat-khau-rieng-2026', $this->dbUser('nhanvien.moi@example.com')->password));
        $this->assertTrue($this->dbUser('nhanvien.moi@example.com')->must_change_password);
    }

    public function test_admin_can_create_users_but_sales_and_branch_managers_cannot(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch);

        $this->spa()->actingAs($sales)->postJson('/api/v1/users', $this->newUser())->assertForbidden();
        $this->spa()->actingAs($manager)->postJson('/api/v1/users', $this->newUser())->assertForbidden();
        $this->assertNull($this->dbUser('nhanvien.moi@example.com'));

        $this->spa()->actingAs($admin)->postJson('/api/v1/users', $this->newUser())->assertCreated();
    }

    public function test_creating_a_user_with_a_role_above_your_own_is_blocked_and_audited(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->spa()->actingAs($admin)->postJson('/api/v1/users', $this->newUser(['role_ids' => [$this->roleOf($tenant, 'owner')->uuid]]))
            ->assertForbidden()->assertJsonPath('code', 'role_exceeds_privileges');

        $this->assertNull($this->dbUser('nhanvien.moi@example.com'), 'Không tạo user nào');
        $log = $this->lastAudit(['action' => 'privilege_escalation_blocked']);
        $this->assertSame('role_exceeds_privileges', $log->reason);
        $this->assertSame($admin->uuid, $log->actor_uuid);
    }

    public function test_roles_and_branches_of_other_tenants_are_rejected(): void
    {
        [, $ownerA] = $this->makeTenant();
        [$tenantB, , $branchB] = $this->makeTenant();

        $this->spa()->actingAs($ownerA)->postJson('/api/v1/users', $this->newUser(['role_ids' => [$this->roleOf($tenantB, 'sales')->uuid]]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['role_ids']]);
        $this->spa()->actingAs($ownerA)->postJson('/api/v1/users', $this->newUser(['branch_ids' => [$branchB->uuid]]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['branch_ids']]);
        $this->assertNull($this->dbUser('nhanvien.moi@example.com'));
    }

    public function test_tenant_and_status_in_the_payload_are_ignored(): void
    {
        [$tenantA, $ownerA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->spa()->actingAs($ownerA)->postJson('/api/v1/users', $this->newUser([
            'tenant_id' => $tenantB->id, 'is_platform_admin' => true, 'status' => 'disabled', 'must_change_password' => false,
        ]))->assertCreated();

        $user = $this->dbUser('nhanvien.moi@example.com');
        $this->assertSame($tenantA->id, $user->tenant_id);
        $this->assertFalse($user->is_platform_admin);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->must_change_password);
    }

    /** Chưa bật subdomain thì địa chỉ không phân biệt được doanh nghiệp, nên email vẫn duy nhất toàn hệ thống (xem EmailPerTenantTest cho trường hợp có subdomain). */
    public function test_without_subdomains_email_must_be_unique_across_the_whole_system_without_revealing_where(): void
    {
        [, $ownerA] = $this->makeTenant();
        [, $ownerB] = $this->makeTenant(user: ['email' => 'da-co@other.test']);

        $response = $this->spa()->actingAs($ownerA)->postJson('/api/v1/users', $this->newUser(['email' => 'DA-CO@other.test']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);

        $this->assertSame('Email này không khả dụng.', $response->json('errors.email.0'));
    }

    public function test_the_email_of_a_deleted_user_stays_reserved(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $gone = $this->makeUserIn($tenant, [], attributes: ['email' => 'da-xoa@example.com']);
        $this->spa()->actingAs($owner)->deleteJson('/api/v1/users/'.$gone->uuid)->assertNoContent();

        $this->spa()->actingAs($owner)->postJson('/api/v1/users', $this->newUser(['email' => 'da-xoa@example.com']))->assertStatus(422);
    }

    public function test_password_policy_is_enforced(): void
    {
        [, $owner] = $this->makeTenant();
        $post = fn (string $password) => $this->spa()->actingAs($owner)->postJson('/api/v1/users', $this->newUser(['password' => $password]));

        $post('ngan1')->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
        $post('khongcoso-nao-ca')->assertStatus(422);
        $post('123456789012')->assertStatus(422);
        $post('Nhanvien.moi-2026')->assertStatus(422); // chứa phần tên đăng nhập của email
        $post('password1234')->assertStatus(422);       // quá phổ biến
        $this->assertNull($this->dbUser('nhanvien.moi@example.com'));
    }

    // --- Sửa / khóa ----------------------------------------------------------------------

    public function test_admin_updates_profile_but_email_is_immutable(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        $staff = $this->makeUserIn($tenant, ['sales']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/users/'.$staff->uuid, ['name' => 'Tên mới', 'phone' => '0911222333'])
            ->assertOk()->assertJsonPath('data.name', 'Tên mới')->assertJsonPath('data.phone', '0911222333');

        $log = $this->lastAudit(['module' => 'user', 'action' => 'updated', 'subject_id' => $staff->uuid]);
        $this->assertSame($staff->name, $log->before['name']);
        $this->assertSame('Tên mới', $log->after['name']);
        $this->assertSame($admin->uuid, $log->actor_uuid);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/users/'.$staff->uuid, ['email' => 'doi@example.com'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_a_user_can_edit_their_own_name_if_they_hold_the_update_permission(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, ['name' => 'Tự đổi tên'])->assertOk();
    }

    public function test_disabling_signs_the_user_out_and_blocks_login_until_re_enabled(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        DB::table('sessions')->insert(['id' => 'staff-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $staff->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->spa()->actingAs($owner)->patchJson('/api/v1/users/'.$staff->uuid, ['status' => 'disabled', 'reason' => 'Nghỉ việc'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->spa()->actingAs($staff->forceFill(['status' => 'disabled']))->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $log = $this->lastAudit(['module' => 'user', 'action' => 'status_changed']);
        $this->assertSame('Nghỉ việc', $log->reason);
        $this->assertSame($owner->uuid, $log->actor_uuid);

        $this->spa()->actingAs($owner)->patchJson('/api/v1/users/'.$staff->uuid, ['status' => 'active'])->assertOk();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertOk();
    }

    public function test_you_cannot_disable_or_delete_yourself(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/users/'.$admin->uuid, ['status' => 'disabled'])
            ->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
        $this->spa()->actingAs($admin)->postJson('/api/v1/users/'.$admin->uuid.'/reset-password')->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');

        [, $owner] = $this->makeTenant();
        $this->spa()->actingAs($owner)->deleteJson('/api/v1/users/'.$owner->uuid)->assertStatus(422)->assertJsonPath('code', 'cannot_modify_self');
    }

    public function test_admin_cannot_touch_users_more_powerful_than_themselves(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        // Một vai trò tùy chỉnh có cả quyền sao lưu mà Admin không có.
        $strong = $this->platform(function () use ($tenant) {
            $role = new Role(['name' => 'Quản trị cấp cao']);
            $role->forceFill(['tenant_id' => $tenant->id, 'key' => 'custom-cao-cap', 'is_system' => false])->save();
            app(RoleService::class)->setGrants($role, ['tenant.user.view' => 'tenant', 'backup.backup.restore' => 'tenant']);

            return $role;
        });
        $manager = $this->makeUserIn($tenant, []);
        $this->platform(fn () => $manager->roles()->attach($strong->id, ['tenant_id' => $tenant->id]));
        DB::table('sessions')->insert(['id' => 'owner-session-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $owner->id, 'payload' => 'x', 'last_activity' => time()]);

        foreach ([$owner, $manager] as $target) {
            $url = '/api/v1/users/'.$target->uuid;
            $this->spa()->actingAs($admin)->patchJson($url, ['name' => 'Bị sửa'])->assertForbidden()->assertJsonPath('code', 'user_exceeds_privileges');
            $this->spa()->actingAs($admin)->patchJson($url, ['status' => 'disabled'])->assertForbidden();
            $this->spa()->actingAs($admin)->deleteJson($url)->assertForbidden();
            $this->spa()->actingAs($admin)->postJson("$url/reset-password")->assertForbidden();
            $this->spa()->actingAs($admin)->putJson("$url/branches", ['branch_ids' => []])->assertForbidden();
        }

        $this->assertSame('active', $this->platform(fn () => User::find($owner->id)->status));
        $this->assertSame(1, DB::table('sessions')->where('user_id', $owner->id)->count(), 'Phiên của Owner không bị đụng tới');
        $blocked = $this->lastAudit(['action' => 'privilege_escalation_blocked', 'reason' => 'user_exceeds_privileges']);
        $this->assertSame($admin->uuid, $blocked->actor_uuid);
    }

    public function test_the_last_active_owner_cannot_be_disabled_or_deleted_by_someone_else(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        // Vai trò tùy chỉnh có quyền tương đương Owner nhưng không phải Owner.
        $godRole = $this->platform(function () use ($tenant) {
            $role = new Role(['name' => 'Siêu quản trị']);
            $role->forceFill(['tenant_id' => $tenant->id, 'key' => 'custom-sieu', 'is_system' => false])->save();
            app(RoleService::class)->setGrants($role, Catalog::roleGrants('owner'));

            return $role;
        });
        $deputy = $this->makeUserIn($tenant, []);
        $this->platform(fn () => $deputy->roles()->attach($godRole->id, ['tenant_id' => $tenant->id]));

        $this->spa()->actingAs($deputy)->patchJson('/api/v1/users/'.$owner->uuid, ['status' => 'disabled'])
            ->assertStatus(422)->assertJsonPath('code', 'last_owner');
        $this->spa()->actingAs($deputy)->deleteJson('/api/v1/users/'.$owner->uuid)->assertStatus(422)->assertJsonPath('code', 'last_owner');
        $this->assertSame('last_owner', $this->lastAudit(['action' => 'user_change_blocked'])->reason);

        // Có Owner thứ hai đang hoạt động thì mới được khóa Owner đầu.
        $second = $this->makeUserIn($tenant, ['owner']);
        $this->spa()->actingAs($deputy)->patchJson('/api/v1/users/'.$owner->uuid, ['status' => 'disabled'])->assertOk();
        $this->spa()->actingAs($deputy)->patchJson('/api/v1/users/'.$second->uuid, ['status' => 'disabled'])
            ->assertStatus(422)->assertJsonPath('code', 'last_owner');
    }

    // --- Xóa ------------------------------------------------------------------------------

    public function test_deleting_a_user_is_soft_signs_them_out_and_is_audited(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        DB::table('sessions')->insert(['id' => 'staff-session-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'user_id' => $staff->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/users/'.$staff->uuid)->assertNoContent();

        $this->assertNotNull($this->platform(fn () => User::withTrashed()->find($staff->id)->deleted_at));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertUnauthorized();
        $this->spa()->actingAs($owner)->getJson('/api/v1/users/'.$staff->uuid)->assertNotFound();

        $log = $this->lastAudit(['module' => 'user', 'action' => 'deleted', 'subject_id' => $staff->uuid]);
        $this->assertSame($owner->uuid, $log->actor_uuid);
        $this->assertSame('[REDACTED]', $log->before['password']);
    }

    // --- Chi nhánh --------------------------------------------------------------------------

    public function test_branches_can_be_reassigned_and_changes_are_audited(): void
    {
        [$tenant, $owner, $first] = $this->makeTenant();
        $second = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'CN-02']));
        $staff = $this->makeUserIn($tenant, ['sales'], $first);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/branches', ['branch_ids' => [$second->uuid]])
            ->assertOk()->assertJsonPath('data.branches.0.id', $second->uuid)->assertJsonCount(1, 'data.branches');

        $log = $this->lastAudit(['action' => 'branches_changed']);
        $this->assertSame([$first->code], $log->before['branches']);
        $this->assertSame(['CN-02'], $log->after['branches']);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/branches', ['branch_ids' => []])->assertOk()->assertJsonCount(0, 'data.branches');
        $this->assertSame(0, $this->platform(fn () => $staff->branches()->count()));
    }

    public function test_assigning_a_branch_from_another_tenant_is_rejected(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        [, , $foreign] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/branches', ['branch_ids' => [$foreign->uuid]])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['branch_ids']]);
    }

    public function test_a_branch_scoped_updater_can_only_manage_branches_inside_their_own_scope(): void
    {
        [$tenant, , $mine] = $this->makeTenant();
        $other = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'CN-KHAC']));
        $staff = $this->makeUserIn($tenant, [], $mine);
        $this->platform(fn () => $staff->branches()->attach($other->id, ['tenant_id' => $tenant->id]));

        // Vai trò tùy chỉnh: sửa người dùng ở phạm vi chi nhánh.
        $role = $this->platform(function () use ($tenant) {
            $r = new Role(['name' => 'Quản lý nhân sự chi nhánh']);
            $r->forceFill(['tenant_id' => $tenant->id, 'key' => 'custom-hr', 'is_system' => false])->save();
            app(RoleService::class)->setGrants($r, ['tenant.user.view' => 'branch', 'tenant.user.update' => 'branch']);

            return $r;
        });
        $hr = $this->makeUserIn($tenant, [], $mine);
        $this->platform(fn () => $hr->roles()->attach($role->id, ['tenant_id' => $tenant->id]));

        // Không gán được chi nhánh ngoài phạm vi của mình...
        $this->spa()->actingAs($hr)->putJson('/api/v1/users/'.$staff->uuid.'/branches', ['branch_ids' => [$mine->uuid, $other->uuid]])
            ->assertForbidden()->assertJsonPath('code', 'branch_exceeds_privileges');

        // ...và khi sửa thì chi nhánh ngoài phạm vi của user được giữ nguyên.
        $this->spa()->actingAs($hr)->putJson('/api/v1/users/'.$staff->uuid.'/branches', ['branch_ids' => []])->assertOk();
        $this->assertSame(['CN-KHAC'], $this->platform(fn () => $staff->branches()->pluck('branches.code')->all()));
    }

    // --- Mật khẩu tạm -------------------------------------------------------------------

    public function test_reset_password_gives_a_temporary_password_signs_the_user_out_and_forces_a_change(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        DB::table('sessions')->insert(['id' => 'staff-session-cccccccccccccccccccccccccccccccccccc', 'user_id' => $staff->id, 'payload' => 'x', 'last_activity' => time()]);

        $response = $this->spa()->actingAs($owner)->postJson('/api/v1/users/'.$staff->uuid.'/reset-password')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $temporary = $response->json('temporary_password');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'password'])->assertUnauthorized();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => $temporary])
            ->assertOk()->assertJsonPath('data.must_change_password', true);

        $log = $this->lastAudit(['action' => 'password_reset']);
        $this->assertSame('tenant_admin', $log->metadata['by']);
        $this->assertSame($owner->uuid, $log->actor_uuid);
        $this->assertStringNotContainsString($temporary, json_encode($this->auditRows()->all()));
    }

    public function test_users_without_update_permission_cannot_reset_passwords(): void
    {
        [$tenant, , $branch] = $this->makeTenant();
        $sales = $this->makeUserIn($tenant, ['sales'], $branch);
        $staff = $this->makeUserIn($tenant, ['delivery'], $branch);

        $this->spa()->actingAs($sales)->postJson('/api/v1/users/'.$staff->uuid.'/reset-password')->assertForbidden();
        $this->spa()->actingAs($sales)->patchJson('/api/v1/users/'.$staff->uuid, ['name' => 'X'])->assertForbidden();
        $this->spa()->actingAs($sales)->deleteJson('/api/v1/users/'.$staff->uuid)->assertForbidden();
    }

    // --- Cô lập Tenant ----------------------------------------------------------------------

    public function test_no_user_management_across_tenants(): void
    {
        [, $ownerA] = $this->makeTenant();
        [$tenantB, , $branchB] = $this->makeTenant();
        $victim = $this->makeUserIn($tenantB, ['sales']);
        $url = '/api/v1/users/'.$victim->uuid;

        $this->spa()->actingAs($ownerA)->getJson($url)->assertNotFound();
        $this->spa()->actingAs($ownerA)->patchJson($url, ['status' => 'disabled'])->assertNotFound();
        $this->spa()->actingAs($ownerA)->deleteJson($url)->assertNotFound();
        $this->spa()->actingAs($ownerA)->postJson("$url/reset-password")->assertNotFound();
        $this->spa()->actingAs($ownerA)->putJson("$url/branches", ['branch_ids' => [$branchB->uuid]])->assertNotFound();
        $this->spa()->actingAs($ownerA)->putJson("$url/roles", ['role_ids' => []])->assertNotFound();

        $fresh = $this->platform(fn () => User::find($victim->id));
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_malformed_ids_return_404_not_500(): void
    {
        [, $owner] = $this->makeTenant();

        foreach (['users', 'branches', 'roles'] as $resource) {
            $this->spa()->actingAs($owner)->getJson("/api/v1/$resource/khong-phai-uuid")->assertNotFound();
        }
        $this->spa()->actingAs($owner)->patchJson('/api/v1/users/12345', ['name' => 'x'])->assertNotFound();
    }

    public function test_listing_includes_branches_and_roles(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();

        $row = collect($this->spa()->actingAs($owner)->getJson('/api/v1/users')->assertOk()->json('data'))->firstWhere('id', $owner->uuid);

        $this->assertSame([$branch->uuid], collect($row['branches'])->pluck('id')->all());
        $this->assertSame(['owner'], collect($row['roles'])->pluck('key')->all());
    }
}
