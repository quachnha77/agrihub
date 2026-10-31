<?php

namespace Tests\Feature\Audit;

use App\Models\Branch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditSuppression;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Các nghiệp vụ hiện có phải để lại audit log đúng người, đúng Tenant, đúng nội dung. */
class AuditRecordingTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    // --- Model events ---------------------------------------------------------------------

    public function test_creating_a_branch_is_audited_with_actor_tenant_branch_and_request_context(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $response = $this->spa()->actingAs($owner)
            ->withHeaders(['User-Agent' => self::CHROME, 'X-Request-Id' => 'req-abc-12345'])
            ->postJson('/api/v1/branches', ['code' => 'HN-01', 'name' => 'Hà Nội'])
            ->assertCreated();

        $response->assertHeader('X-Request-Id', 'req-abc-12345');
        $uuid = $response->json('data.id');

        $log = $this->lastAudit(['module' => 'branch', 'action' => 'created']);

        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('user', $log->actor_type);
        $this->assertSame($owner->uuid, $log->actor_uuid);
        $this->assertStringContainsString($owner->email, $log->actor_label);
        $this->assertSame('branch', $log->subject_type);
        $this->assertSame($uuid, $log->subject_id);
        $this->assertSame('HN-01 - Hà Nội', $log->subject_label);
        $this->assertSame($this->platform(fn () => Branch::where('uuid', $uuid)->value('id')), $log->branch_id);
        $this->assertSame('success', $log->result);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame(self::CHROME, $log->user_agent);
        $this->assertSame('req-abc-12345', $log->request_id);
        $this->assertSame('HN-01', $log->after['code']);
        $this->assertNull($log->before);
    }

    public function test_client_cannot_spoof_the_logged_ip_through_forwarding_headers(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)
            ->withHeaders(['X-Forwarded-For' => '6.6.6.6', 'X-Real-IP' => '7.7.7.7'])
            ->postJson('/api/v1/branches', ['code' => 'SPOOF', 'name' => 'Giả IP'])->assertCreated();

        $this->assertSame('127.0.0.1', $this->lastAudit(['module' => 'branch', 'action' => 'created'])->ip_address);
    }

    public function test_updating_records_only_the_changed_fields_with_before_and_after(): void
    {
        [, $owner, $branch] = $this->makeTenant();

        $this->spa()->actingAs($owner)->patchJson('/api/v1/branches/'.$branch->uuid, ['name' => 'Tên mới', 'phone' => '0901234567'])->assertOk();

        $log = $this->lastAudit(['module' => 'branch', 'action' => 'updated']);

        $this->assertEquals(['name' => 'Tên mới', 'phone' => '0901234567'], $log->after);
        $this->assertSame($branch->name, $log->before['name']);
        $this->assertSame($branch->phone, $log->before['phone']);
        $this->assertArrayNotHasKey('code', $log->after);
        $this->assertArrayNotHasKey('updated_at', $log->after);
    }

    public function test_an_update_that_changes_nothing_writes_no_log(): void
    {
        [, $owner, $branch] = $this->makeTenant();
        $before = $this->auditCount(['action' => 'updated']);

        $this->spa()->actingAs($owner)->patchJson('/api/v1/branches/'.$branch->uuid, ['name' => $branch->name])->assertOk();

        $this->assertSame($before, $this->auditCount(['action' => 'updated']));
    }

    public function test_deleting_a_branch_is_audited_with_its_previous_state(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $empty = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'XOA-1']));

        $this->spa()->actingAs($owner)->deleteJson('/api/v1/branches/'.$empty->uuid)->assertNoContent();

        $log = $this->lastAudit(['module' => 'branch', 'action' => 'deleted']);
        $this->assertSame('XOA-1', $log->before['code']);
        $this->assertSame($empty->uuid, $log->subject_id);
        $this->assertSame($owner->uuid, $log->actor_uuid);
    }

    public function test_hidden_attributes_are_masked_and_hashes_never_stored(): void
    {
        [$tenant] = $this->makeTenant();
        $user = $this->makeUserIn($tenant, [], attributes: ['email' => 'moi@example.com']);

        $log = $this->lastAudit(['module' => 'user', 'action' => 'created', 'subject_id' => $user->uuid]);

        $this->assertSame('[REDACTED]', $log->after['password']);
        $this->assertArrayNotHasKey('remember_token', $log->after, 'remember_token không bao giờ vào log');
        $this->assertSame('moi@example.com', $log->after['email']);

        $hash = $this->platform(fn () => User::find($user->id)->getAuthPassword());
        $dump = json_encode($this->auditRows()->all());
        $this->assertStringNotContainsString($hash, $dump);
    }

    public function test_login_bookkeeping_does_not_pollute_the_log_with_user_updates(): void
    {
        [, $user] = $this->makeTenant();
        $before = $this->auditCount(['module' => 'user', 'action' => 'updated']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->assertSame($before, $this->auditCount(['module' => 'user', 'action' => 'updated']));
    }

    public function test_actions_without_a_request_are_recorded_as_system(): void
    {
        [$tenant] = $this->makeTenant();

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'created', 'subject_id' => $tenant->uuid]);

        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->ip_address);
        $this->assertSame($tenant->id, $log->tenant_id);
    }

    public function test_provisioning_system_roles_writes_one_summary_event_not_one_per_role(): void
    {
        [$tenant] = $this->makeTenant();

        $provisioned = $this->auditRows(['tenant_id' => $tenant->id, 'action' => 'system_roles_provisioned']);
        $this->assertCount(1, $provisioned);
        $this->assertCount(7, $provisioned->first()->metadata['created']);
        $this->assertSame(0, $this->auditCount(['tenant_id' => $tenant->id, 'module' => 'rbac', 'action' => 'created']));
    }

    public function test_suppression_helper_skips_model_events_only_while_active(): void
    {
        [$tenant] = $this->makeTenant();

        $this->platform(function () use ($tenant) {
            AuditSuppression::run(fn () => Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'AN-1']));
            Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'HIEN-1']);
        });

        $labels = $this->auditRows(['module' => 'branch', 'action' => 'created'])->pluck('subject_label')->all();
        $this->assertContains(true, array_map(fn ($l) => str_starts_with($l, 'HIEN-1'), $labels));
        $this->assertNotContains(true, array_map(fn ($l) => str_starts_with($l, 'AN-1'), $labels));
        $this->assertFalse(AuditSuppression::active());
    }

    // --- Authentication -------------------------------------------------------------------

    public function test_successful_login_and_logout_are_audited(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->withHeaders(['User-Agent' => self::CHROME])->spa()
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $login = $this->lastAudit(['module' => 'auth', 'action' => 'login']);
        $this->assertSame($user->uuid, $login->actor_uuid);
        $this->assertSame($tenant->id, $login->tenant_id);
        $this->assertSame('success', $login->result);
        $this->assertSame(self::CHROME, $login->user_agent);

        $this->spa()->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

        $logout = $this->lastAudit(['module' => 'auth', 'action' => 'logout']);
        $this->assertSame($user->uuid, $logout->actor_uuid);
    }

    public function test_failed_login_for_unknown_email_is_audited_without_tenant_and_without_password(): void
    {
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'La@Example.com', 'password' => 'mat-khau-bi-mat-123'])->assertUnauthorized();

        $log = $this->lastAudit(['module' => 'auth', 'action' => 'login_failed']);

        $this->assertSame('failure', $log->result);
        $this->assertSame('invalid_credentials', $log->reason);
        $this->assertSame('anonymous', $log->actor_type);
        $this->assertNull($log->tenant_id);
        $this->assertNull($log->subject_type);
        $this->assertSame('la@example.com', $log->metadata['email']);
        $this->assertStringNotContainsString('mat-khau-bi-mat-123', json_encode($this->auditRows()->all()));
    }

    public function test_failed_login_for_known_user_is_attributed_to_their_tenant(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'sai'])->assertUnauthorized();

        $log = $this->lastAudit(['action' => 'login_failed']);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame($user->uuid, $log->subject_id);
        $this->assertNull($log->actor_id, 'Chưa xác thực nên không có actor');
    }

    public function test_lockout_and_attempts_while_locked_are_audited(): void
    {
        [$tenant, $user] = $this->makeTenant();
        $this->platform(fn () => $user->forceFill(['failed_login_attempts' => 4])->save());

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'sai'])->assertUnauthorized();

        $locked = $this->lastAudit(['action' => 'account_locked']);
        $this->assertSame('denied', $locked->result);
        $this->assertSame($tenant->id, $locked->tenant_id);
        $this->assertSame(15, $locked->metadata['locked_minutes']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);

        $attempt = $this->lastAudit(['action' => 'login_failed']);
        $this->assertSame('account_locked', $attempt->reason);
    }

    public function test_disabled_user_and_suspended_tenant_logins_are_audited_with_reason(): void
    {
        [, $disabled] = $this->makeTenant(user: ['status' => User::STATUS_DISABLED]);
        [, $suspended] = $this->makeTenant(tenant: ['status' => 'suspended']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $disabled->email, 'password' => 'password'])->assertForbidden();
        $this->assertSame('account_disabled', $this->lastAudit(['action' => 'login_failed'])->reason);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => $suspended->email, 'password' => 'password'])->assertForbidden();
        $this->assertSame('tenant_suspended', $this->lastAudit(['action' => 'login_failed'])->reason);
    }

    public function test_revoking_sessions_is_audited(): void
    {
        [, $user] = $this->makeTenant();
        \DB::table('sessions')->insert(['id' => 'other-device-session-aaaaaaaaaaaaaaaaaaaaaaaa', 'user_id' => $user->id, 'ip_address' => '10.1.1.1',
            'user_agent' => self::CHROME, 'payload' => 'x', 'last_activity' => time()]);

        $this->spa()->actingAs($user)->deleteJson('/api/v1/auth/sessions')->assertNoContent();

        $log = $this->lastAudit(['action' => 'sessions_revoked_others']);
        $this->assertSame(1, $log->metadata['count']);
        $this->assertSame($user->uuid, $log->actor_uuid);
    }

    // --- RBAC -----------------------------------------------------------------------------

    public function test_role_permission_changes_are_audited_with_before_and_after(): void
    {
        [$tenant, $owner] = $this->makeTenant();

        $uuid = $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'Tùy chỉnh', 'permissions' => [['key' => 'report.report.view', 'scope' => 'own']],
        ])->assertCreated()->json('data.id');

        $created = $this->lastAudit(['module' => 'rbac', 'action' => 'created', 'subject_id' => $uuid]);
        $this->assertSame($owner->uuid, $created->actor_uuid);

        $initial = $this->lastAudit(['action' => 'role_permissions_changed', 'subject_id' => $uuid]);
        $this->assertSame([], $initial->before['permissions']);
        $this->assertSame(['report.report.view' => 'own'], $initial->after['permissions']);

        $this->spa()->actingAs($owner)->putJson('/api/v1/roles/'.$uuid, [
            'permissions' => [['key' => 'report.report.view', 'scope' => 'branch'], ['key' => 'report.report.export', 'scope' => 'branch']],
        ])->assertOk();

        $change = $this->lastAudit(['action' => 'role_permissions_changed', 'subject_id' => $uuid]);
        $this->assertSame(['report.report.view' => 'own'], $change->before['permissions']);
        $this->assertEquals(['report.report.view' => 'branch', 'report.report.export' => 'branch'], $change->after['permissions']);
        $this->assertSame($tenant->id, $change->tenant_id);
    }

    public function test_renaming_a_role_without_touching_permissions_writes_only_the_model_event(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $role = $this->roleOf($tenant, 'sales');
        $before = $this->auditCount(['action' => 'role_permissions_changed']);

        $this->spa()->actingAs($owner)->patchJson('/api/v1/roles/'.$role->uuid, ['name' => 'Bán hàng (đổi tên)'])->assertOk();

        $this->assertSame($before, $this->auditCount(['action' => 'role_permissions_changed']));
        $this->assertSame('Bán hàng (đổi tên)', $this->lastAudit(['module' => 'rbac', 'action' => 'updated'])->after['name']);
    }

    public function test_privilege_escalation_attempts_are_audited_as_denied(): void
    {
        [$tenant] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->spa()->actingAs($admin)->postJson('/api/v1/roles', [
            'name' => 'Leo thang', 'permissions' => [['key' => 'billing.subscription.update', 'scope' => 'tenant']],
        ])->assertForbidden();

        $log = $this->lastAudit(['action' => 'privilege_escalation_blocked']);
        $this->assertSame('denied', $log->result);
        $this->assertSame('permission_exceeds_privileges', $log->reason);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame('tenant', $log->metadata['exceeding']['billing.subscription.update']);
    }

    public function test_user_role_changes_and_blocked_attempts_are_audited(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);
        $staff = $this->makeUserIn($tenant, ['sales']);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$staff->uuid.'/roles', [
            'role_ids' => [$this->roleOf($tenant, 'warehouse')->uuid, $this->roleOf($tenant, 'accountant')->uuid],
        ])->assertOk();

        $log = $this->lastAudit(['action' => 'user_roles_changed']);
        $this->assertSame(['sales'], $log->before['roles']);
        $this->assertSame(['accountant', 'warehouse'], $log->after['roles']);
        $this->assertSame($staff->uuid, $log->subject_id);
        $this->assertSame($owner->uuid, $log->actor_uuid);

        $this->spa()->actingAs($admin)->putJson('/api/v1/users/'.$staff->uuid.'/roles', [
            'role_ids' => [$this->roleOf($tenant, 'owner')->uuid],
        ])->assertForbidden();

        $blocked = $this->lastAudit(['action' => 'privilege_escalation_blocked']);
        $this->assertSame('role_exceeds_privileges', $blocked->reason);
        $this->assertSame($admin->uuid, $blocked->actor_uuid);
        $this->assertSame(['owner'], $blocked->metadata['requested']);

        $this->spa()->actingAs($owner)->putJson('/api/v1/users/'.$owner->uuid.'/roles', ['role_ids' => []])->assertStatus(422);
        $this->assertSame('last_owner', $this->lastAudit(['action' => 'user_change_blocked'])->reason);
    }

    // --- Ghi trực tiếp --------------------------------------------------------------------

    public function test_secrets_passed_to_the_logger_are_redacted(): void
    {
        [$tenant] = $this->makeTenant();

        $this->platform(fn () => app(AuditLogger::class)->record(
            'test', 'secrets',
            before: ['password' => 'hunter2'],
            after: ['api_key' => 'sk-123', 'ok' => 'visible'],
            metadata: ['nested' => ['access_token' => 'tok-999', 'note' => 'ghi chú']],
            tenantId: $tenant->id,
        ));

        $log = $this->lastAudit(['module' => 'test']);
        $this->assertSame('[REDACTED]', $log->before['password']);
        $this->assertSame('[REDACTED]', $log->after['api_key']);
        $this->assertSame('visible', $log->after['ok']);
        $this->assertSame('[REDACTED]', $log->metadata['nested']['access_token']);
        $this->assertSame('ghi chú', $log->metadata['nested']['note']);
        $this->assertStringNotContainsString('hunter2', json_encode($this->auditRows()->all()));
    }

    public function test_tenant_is_inferred_from_actor_or_context_and_can_be_overridden(): void
    {
        [$tenantA, $userA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $logger = app(AuditLogger::class);

        $this->platform(fn () => $logger->record('test', 'from_actor', actor: $userA));
        $this->assertSame($tenantA->id, $this->lastAudit(['action' => 'from_actor'])->tenant_id);

        $this->platform(fn () => $logger->record('test', 'explicit', actor: $userA, tenantId: $tenantB->id));
        $this->assertSame($tenantB->id, $this->lastAudit(['action' => 'explicit'])->tenant_id);

        $this->platform(fn () => $logger->record('test', 'global', actor: null));
        $this->assertNull($this->lastAudit(['action' => 'global'])->tenant_id);
    }

    public function test_an_invalid_result_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->platform(fn () => app(AuditLogger::class)->record('test', 'bad', result: 'maybe'));
    }

    public function test_audit_row_is_rolled_back_together_with_the_business_change(): void
    {
        [$tenant] = $this->makeTenant();
        $before = $this->auditCount(['module' => 'branch', 'action' => 'created']);

        try {
            \DB::transaction(function () use ($tenant) {
                app(TenantContext::class)->runForTenant($tenant->id, fn () => Branch::create(['code' => 'ROLLBACK', 'name' => 'Sẽ bị hủy']));
                throw new \RuntimeException('hủy giao dịch');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $this->auditCount(['module' => 'branch', 'action' => 'created']));
        $this->assertNull($this->platform(fn () => Branch::where('code', 'ROLLBACK')->first()));
    }
}
