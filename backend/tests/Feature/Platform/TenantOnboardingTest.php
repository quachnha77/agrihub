<?php

namespace Tests\Feature\Platform;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'tenant' => ['name' => 'Cửa hàng Nông Phát', 'phone' => '0901234567', 'address' => '12 Nguyễn Huệ, Q1', 'tax_code' => '0123456789'],
            'owner' => ['name' => 'Nguyễn Văn A', 'email' => 'ChuShop@NongPhat.vn'],
        ], $override);
    }

    public function test_platform_admin_onboards_a_tenant_with_branch_owner_and_roles(): void
    {
        $admin = $this->makePlatformAdmin();

        $response = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cửa hàng Nông Phát')
            ->assertJsonPath('data.slug', 'cua-hang-nong-phat')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tax_code', '0123456789')
            ->assertJsonPath('data.users_count', 1)
            ->assertJsonPath('data.branches_count', 1)
            ->assertJsonPath('data.owners.0.email', 'chushop@nongphat.vn')
            ->assertJsonPath('data.owners.0.must_change_password', true)
            ->assertJsonPath('data.branches.0.code', 'CN-01')
            ->assertJsonPath('data.branches.0.name', 'Chi nhánh chính');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $temporary = $response->json('temporary_password');
        $this->assertIsString($temporary);
        $this->assertGreaterThanOrEqual(12, strlen($temporary));

        $tenant = $this->platform(fn () => Tenant::where('uuid', $response->json('data.id'))->firstOrFail());
        $owner = $this->platform(fn () => User::where('email', 'chushop@nongphat.vn')->firstOrFail());

        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertFalse($owner->is_platform_admin);
        $this->assertTrue($owner->must_change_password);
        $this->assertSame(['owner'], $this->platform(fn () => $owner->roles()->pluck('key')->all()));
        $this->assertSame(1, $this->platform(fn () => $owner->branches()->count()));
        $this->assertEqualsCanonicalizing(
            ['owner', 'admin', 'branch_manager', 'accountant', 'warehouse', 'sales', 'delivery'],
            $this->platform(fn () => Role::where('tenant_id', $tenant->id)->pluck('key')->all()),
        );
        $this->assertTrue(\Hash::check($temporary, $owner->password));
    }

    public function test_a_supplied_password_is_used_and_never_echoed(): void
    {
        $admin = $this->makePlatformAdmin();

        $response = $this->spa()->actingAs($admin)
            ->postJson('/api/v1/platform/tenants', $this->payload(['owner' => ['password' => 'Mat-khau-manh-2026']]))
            ->assertCreated()
            ->assertJsonMissingPath('temporary_password');

        $this->assertStringNotContainsString('Mat-khau-manh-2026', $response->getContent());
        $owner = $this->platform(fn () => User::where('email', 'chushop@nongphat.vn')->first());
        $this->assertTrue(\Hash::check('Mat-khau-manh-2026', $owner->password));
        $this->assertTrue($owner->must_change_password, 'Dù admin đặt mật khẩu, Owner vẫn phải tự đổi ở lần đầu');
    }

    public function test_custom_slug_and_first_branch_are_respected(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload([
            'tenant' => ['slug' => 'nongphat'],
            'branch' => ['code' => 'HCM-01', 'name' => 'Chi nhánh Quận 1'],
        ]))->assertCreated()->assertJsonPath('data.slug', 'nongphat')->assertJsonPath('data.branches.0.code', 'HCM-01');
    }

    public function test_slug_is_generated_uniquely_from_the_name(): void
    {
        $admin = $this->makePlatformAdmin();

        $first = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload())->assertCreated();
        $second = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload(['owner' => ['email' => 'khac@nongphat.vn']]))->assertCreated();

        $this->assertSame('cua-hang-nong-phat', $first->json('data.slug'));
        $this->assertSame('cua-hang-nong-phat-2', $second->json('data.slug'));
    }

    public function test_generated_slug_skips_reserved_words(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload(['tenant' => ['name' => 'Admin']]))
            ->assertCreated()->assertJsonPath('data.slug', 'admin-2');
    }

    public function test_onboarding_is_audited_without_secrets(): void
    {
        $admin = $this->makePlatformAdmin();

        $response = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload())->assertCreated();
        $tenant = $this->platform(fn () => Tenant::where('uuid', $response->json('data.id'))->first());

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'onboarded']);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame('chushop@nongphat.vn', $log->metadata['owner']);
        $this->assertTrue($log->metadata['temp_login_issued']);

        $this->assertGreaterThan(0, $this->auditCount(['module' => 'tenant', 'action' => 'created', 'tenant_id' => $tenant->id]));
        $this->assertSame(1, $this->auditCount(['action' => 'system_roles_provisioned', 'tenant_id' => $tenant->id]));
        $this->assertStringNotContainsString($response->json('temporary_password'), json_encode($this->auditRows()->all()));
    }

    public function test_validation_rules(): void
    {
        config(['platform.rate_limits.sensitive' => 1000]); // test này gửi nhiều request liên tiếp
        $admin = $this->makePlatformAdmin();
        [, $existing] = $this->makeTenant(tenant: ['slug' => 'da-ton-tai'], user: ['email' => 'trung@example.com']);
        $post = fn (array $override) => $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload($override));

        $post(['tenant' => ['slug' => 'da-ton-tai']])->assertStatus(422)->assertJsonStructure(['errors' => ['tenant.slug']]);
        $post(['tenant' => ['slug' => 'admin']])->assertStatus(422)->assertJsonStructure(['errors' => ['tenant.slug']]);
        $post(['tenant' => ['slug' => 'Co Dau!']])->assertStatus(422)->assertJsonStructure(['errors' => ['tenant.slug']]);
        $post(['tenant' => ['tax_code' => '123']])->assertStatus(422)->assertJsonStructure(['errors' => ['tenant.tax_code']]);
        $post(['tenant' => ['phone' => 'abc']])->assertStatus(422)->assertJsonStructure(['errors' => ['tenant.phone']]);
        $post(['owner' => ['email' => 'khong-phai-email']])->assertStatus(422)->assertJsonStructure(['errors' => ['owner.email']]);
        $post(['owner' => ['email' => 'TRUNG@example.com']])->assertStatus(422)->assertJsonStructure(['errors' => ['owner.email']]);
        $post(['owner' => ['password' => 'ngan1']])->assertStatus(422)->assertJsonStructure(['errors' => ['owner.password']]);
        $post(['owner' => ['password' => 'chunhungkhongso']])->assertStatus(422)->assertJsonStructure(['errors' => ['owner.password']]);
        $post(['owner' => ['password' => 'xChuShop12345']])->assertStatus(422)->assertJsonStructure(['errors' => ['owner.password']]);
        $post(['branch' => ['code' => 'có dấu cách']])->assertStatus(422)->assertJsonStructure(['errors' => ['branch.code']]);

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', [])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['tenant', 'owner']]);

        $this->assertSame(1, $this->platform(fn () => Tenant::count()), 'Không tạo Tenant nào khi validate thất bại');
    }

    public function test_email_of_a_soft_deleted_user_stays_reserved(): void
    {
        $admin = $this->makePlatformAdmin();
        [, $gone] = $this->makeTenant(user: ['email' => 'da-xoa@example.com']);
        $this->platform(fn () => $gone->delete());

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload(['owner' => ['email' => 'da-xoa@example.com']]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['owner.email']]);
    }

    public function test_the_duplicate_email_message_does_not_reveal_which_tenant_has_it(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->makeTenant(user: ['email' => 'bi-mat@example.com']);

        $message = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload(['owner' => ['email' => 'bi-mat@example.com']]))
            ->assertStatus(422)->json('errors')['owner.email'][0];

        $this->assertSame('Email này không khả dụng.', $message);
    }

    public function test_a_failure_halfway_rolls_back_everything(): void
    {
        $admin = $this->makePlatformAdmin();
        User::creating(fn () => throw new RuntimeException('lỗi giữa chừng'));

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload())->assertStatus(500);

        $this->assertNull($this->platform(fn () => Tenant::withTrashed()->where('slug', 'cua-hang-nong-phat')->first()));
        $this->assertSame(0, $this->platform(fn () => Branch::withTrashed()->count()));
        $this->assertSame(0, $this->platform(fn () => Role::count()), 'Vai trò hệ thống cũng bị hủy');
        $this->assertSame(0, $this->auditCount(['action' => 'onboarded']));
    }

    public function test_only_platform_admins_can_onboard(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->postJson('/api/v1/platform/tenants', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertSame(1, $this->platform(fn () => Tenant::count()));
    }

    public function test_anonymous_cannot_onboard(): void
    {
        $this->spa()->postJson('/api/v1/platform/tenants', $this->payload())->assertUnauthorized();
    }

    public function test_newly_onboarded_tenant_is_isolated_from_existing_ones(): void
    {
        $admin = $this->makePlatformAdmin();
        [, , $otherBranch] = $this->makeTenant();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants', $this->payload())->assertCreated();
        $this->freshBrowser(); // mỗi người dùng một phiên (Sanctum gắn phiên với hash mật khẩu của người đó)

        $newOwner = $this->platform(fn () => User::where('email', 'chushop@nongphat.vn')->first())->forceFill(['must_change_password' => false]);

        $this->spa()->actingAs($newOwner)->getJson('/api/v1/branches/'.$otherBranch->uuid)->assertNotFound();
        $ids = collect($this->spa()->actingAs($newOwner)->getJson('/api/v1/branches')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($otherBranch->uuid));
        $this->assertCount(1, $ids);
    }
}
