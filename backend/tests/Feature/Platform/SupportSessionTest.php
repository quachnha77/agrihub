<?php

namespace Tests\Feature\Platform;

use App\Models\Branch;
use App\Models\User;
use App\Support\Platform\SupportSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/**
 * Phiên hỗ trợ (impersonation an toàn): chỉ đọc, có lý do, có hạn, ràng buộc đúng admin, ghi audit đầy đủ.
 */
class SupportSessionTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const REASON = 'Khách báo lỗi không xem được công nợ (ticket #1234)';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function start($admin, $tenant, array $body = [])
    {
        return $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/support-session', $body + ['reason' => self::REASON]);
    }

    // --- Bắt đầu / kết thúc --------------------------------------------------------------

    public function test_platform_admin_starts_a_read_only_session_with_reason_and_expiry(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant(['name' => 'Cửa hàng được hỗ trợ']);

        $response = $this->start($admin, $tenant)->assertCreated()
            ->assertJsonPath('data.tenant.id', $tenant->uuid)
            ->assertJsonPath('data.tenant.name', 'Cửa hàng được hỗ trợ')
            ->assertJsonPath('data.reason', self::REASON)
            ->assertJsonPath('data.read_only', true);

        $expires = Carbon::parse($response->json('data.expires_at'));
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($expires), 5, 'Mặc định 30 phút');
        $this->assertArrayNotHasKey('admin_id', $response->json('data'));
        $this->assertArrayNotHasKey('tenant_id', $response->json('data'));

        $log = $this->lastAudit(['module' => 'platform', 'action' => 'support_session_started']);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame(self::REASON, $log->reason);
        $this->assertSame($response->json('data.id'), $log->metadata['support_session']);
    }

    public function test_custom_duration_is_respected_and_bounded(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();

        $this->start($admin, $tenant, ['minutes' => 4])->assertStatus(422)->assertJsonStructure(['errors' => ['minutes']]);
        $this->start($admin, $tenant, ['minutes' => 121])->assertStatus(422);

        $response = $this->start($admin, $tenant, ['minutes' => 10])->assertCreated();
        $this->assertEqualsWithDelta(600, now()->diffInSeconds(Carbon::parse($response->json('data.expires_at'))), 5);
    }

    public function test_a_meaningful_reason_is_required(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();

        $this->start($admin, $tenant, ['reason' => 'xem thử'])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/support-session', [])->assertStatus(422);
        $this->assertSame(0, $this->auditCount(['action' => 'support_session_started']));
    }

    public function test_only_platform_admins_can_start_a_session(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $this->start($owner, $tenant)->assertForbidden();
    }

    public function test_anonymous_users_cannot_start_a_session(): void
    {
        [$tenant] = $this->makeTenant();

        $this->spa()->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/support-session', ['reason' => self::REASON])->assertUnauthorized();
    }

    public function test_unknown_tenant_returns_404(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/tenants/00000000-0000-4000-8000-000000000000/support-session', ['reason' => self::REASON])->assertNotFound();
    }

    public function test_a_stateful_session_is_required(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();

        $this->actingAs($admin)->postJson('/api/v1/platform/tenants/'.$tenant->uuid.'/support-session', ['reason' => self::REASON])
            ->assertStatus(400)->assertJsonPath('code', 'stateful_request_required');
    }

    public function test_only_one_session_at_a_time(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->start($admin, $tenantA)->assertCreated();
        $this->start($admin, $tenantB)->assertStatus(409)->assertJsonPath('code', 'support_session_active');
    }

    public function test_sessions_can_target_suspended_tenants(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant(['status' => 'suspended']);

        $this->start($admin, $tenant)->assertCreated();
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertOk();
    }

    public function test_status_endpoint_and_ending_a_session(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/support-session')->assertOk()->assertJsonPath('data', null);

        $this->start($admin, $tenant)->assertCreated();
        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/support-session')->assertOk()->assertJsonPath('data.tenant.id', $tenant->uuid);

        $this->spa()->actingAs($admin)->deleteJson('/api/v1/platform/support-session')->assertNoContent();
        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/support-session')->assertOk()->assertJsonPath('data', null);
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'tenant_context_required');

        $ended = $this->lastAudit(['action' => 'support_session_ended']);
        $this->assertSame($tenant->id, $ended->tenant_id);
        $this->assertSame('admin', $ended->metadata['ended_by']);
        $this->assertSame($admin->uuid, $ended->actor_uuid);
    }

    public function test_ending_without_a_session_is_a_harmless_noop(): void
    {
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($admin)->deleteJson('/api/v1/platform/support-session')->assertNoContent();
        $this->assertSame(0, $this->auditCount(['action' => 'support_session_ended']));
    }

    // --- Trong phiên ---------------------------------------------------------------------

    public function test_inside_a_session_the_admin_sees_only_the_supported_tenant(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner, $branch] = $this->makeTenant();
        [, , $foreignBranch] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();

        $ids = collect($this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$branch->uuid], $ids->all());

        $this->spa()->actingAs($admin)->getJson('/api/v1/branches/'.$branch->uuid)->assertOk();
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches/'.$foreignBranch->uuid)->assertNotFound();
        $this->spa()->actingAs($admin)->getJson('/api/v1/users/'.$owner->uuid)->assertOk();
        $this->spa()->actingAs($admin)->getJson('/api/v1/roles')->assertOk();
        $this->spa()->actingAs($admin)->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_client_supplied_tenant_hints_are_ignored(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, , $branch] = $this->makeTenant();
        [$other, , $otherBranch] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();

        $ids = collect($this->spa()->actingAs($admin)->withHeaders(['X-Tenant-Id' => (string) $other->id])
            ->getJson('/api/v1/branches?tenant_id='.$other->id.'&tenant='.$other->uuid)->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$branch->uuid], $ids->all());
        $this->assertFalse($ids->contains($otherBranch->uuid));
    }

    public function test_writes_are_refused_inside_a_session(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner, $branch] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();
        $as = fn () => $this->spa()->actingAs($admin);

        $as()->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'Không được tạo'])->assertForbidden()->assertJsonPath('code', 'support_session_read_only');
        $as()->patchJson('/api/v1/branches/'.$branch->uuid, ['name' => 'Không được sửa'])->assertForbidden()->assertJsonPath('code', 'support_session_read_only');
        $as()->deleteJson('/api/v1/branches/'.$branch->uuid)->assertForbidden()->assertJsonPath('code', 'support_session_read_only');
        $as()->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
        $as()->putJson('/api/v1/users/'.$owner->uuid.'/roles', ['role_ids' => []])->assertForbidden();
        $as()->postJson('/api/v1/users/'.$owner->uuid.'/reset-password')->assertForbidden();
        $as()->postJson('/api/v1/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();

        $this->assertSame(1, $this->platform(fn () => Branch::where('tenant_id', $tenant->id)->count()));
        $this->assertTrue($this->platform(fn () => User::find($owner->id)->roles()->where('key', 'owner')->exists()));
    }

    public function test_effective_permissions_inside_a_session_are_view_only(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();

        $me = $this->spa()->actingAs($admin)->getJson('/api/v1/auth/me')->assertOk();

        $permissions = array_keys($me->json('data.permissions'));
        $this->assertNotEmpty($permissions);
        foreach ($permissions as $key) {
            $this->assertStringEndsWith('.view', $key);
        }
        $this->assertSame($tenant->uuid, $me->json('data.support_session.tenant.id'));
        $this->assertTrue($me->json('data.support_session.read_only'));
    }

    public function test_me_has_no_permissions_or_support_session_outside_a_session(): void
    {
        $admin = $this->makePlatformAdmin();

        $me = $this->spa()->actingAs($admin)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame([], $me->json('data.permissions'));
        $this->assertNull($me->json('data.support_session'));
    }

    public function test_platform_endpoints_keep_working_and_see_every_tenant_during_a_session(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();

        $this->assertCount(2, $this->spa()->actingAs($admin)->getJson('/api/v1/platform/tenants')->assertOk()->json('data'));
        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/audit-logs')->assertOk();
    }

    // --- Dấu vết -------------------------------------------------------------------------

    public function test_every_access_is_audited_and_visible_to_the_tenant_itself(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant, $owner] = $this->makeTenant();
        $sessionId = $this->start($admin, $tenant)->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->getJson('/api/v1/users?q=bí+mật')->assertOk();
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertOk();
        $this->spa()->actingAs($admin)->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'Bị chặn'])->assertForbidden();

        $requests = $this->auditRows(['action' => 'support_request']);
        $this->assertCount(2, $requests, 'Chỉ ghi các truy cập hợp lệ, không ghi request bị chặn ghi');
        $this->assertSame(['/api/v1/users', '/api/v1/branches'], $requests->pluck('metadata.path')->all(), 'Không ghi query string');
        foreach ($requests as $row) {
            $this->assertSame($tenant->id, $row->tenant_id);
            $this->assertSame($admin->uuid, $row->actor_uuid);
            $this->assertSame($sessionId, $row->metadata['support_session']);
        }

        // Chính Tenant thấy được ai của AgriHub đã vào xem và vì lý do gì.
        $visible = collect($this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=platform&per_page=100')->assertOk()->json('data'));
        $this->assertTrue($visible->contains(fn ($r) => $r['action'] === 'support_session_started' && $r['reason'] === self::REASON));
        $this->assertSame(2, $visible->where('action', 'support_request')->count());
    }

    public function test_tenant_users_never_get_support_audit_noise_from_their_own_requests(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->getJson('/api/v1/branches')->assertOk();

        $this->assertSame(0, $this->auditCount(['action' => 'support_request']));
    }

    // --- Hết hạn / ràng buộc ------------------------------------------------------------

    public function test_the_session_expires_and_expiry_is_audited_once(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $id = $this->start($admin, $tenant, ['minutes' => 5])->assertCreated()->json('data.id');

        Carbon::setTestNow(now()->addMinutes(4));
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'tenant_context_required');
        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden();

        $expired = $this->auditRows(['action' => 'support_session_expired']);
        $this->assertCount(1, $expired);
        $this->assertSame($id, $expired->first()->metadata['support_session']);
        $this->assertSame($tenant->id, $expired->first()->tenant_id);
    }

    public function test_a_session_belongs_to_the_admin_who_started_it(): void
    {
        $adminA = $this->makePlatformAdmin();
        $adminB = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $this->start($adminA, $tenant)->assertCreated();

        // Dữ liệu session của A bị "đưa" sang phiên của B (vd. dùng chung session): phải bị bác bỏ.
        $this->spa()->actingAs($adminB)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'tenant_context_required');
        $this->assertNull(session(SupportSession::KEY));
    }

    public function test_logging_out_ends_the_session(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();

        $this->spa()->actingAs($admin)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'tenant_context_required');
    }

    public function test_a_disabled_admin_loses_access_even_with_an_active_session(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $this->start($admin, $tenant)->assertCreated();
        $this->platform(fn () => $admin->forceFill(['status' => 'disabled'])->save());

        $this->spa()->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'account_disabled');
    }

    public function test_audit_entries_made_during_a_session_carry_its_id(): void
    {
        $admin = $this->makePlatformAdmin();
        [$tenant] = $this->makeTenant();
        $id = $this->start($admin, $tenant)->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->deleteJson('/api/v1/platform/support-session')->assertNoContent();

        $this->assertSame($id, $this->lastAudit(['action' => 'support_session_ended'])->metadata['support_session']);
    }
}
