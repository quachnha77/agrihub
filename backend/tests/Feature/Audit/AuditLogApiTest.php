<?php

namespace Tests\Feature\Audit;

use App\Models\Branch;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    public function test_owner_sees_own_tenant_logs_only(): void
    {
        [$tenantA, $ownerA, $branchA] = $this->makeTenant();
        [, , $branchB] = $this->makeTenant();

        $data = $this->spa()->actingAs($ownerA)->getJson('/api/v1/audit-logs?module=branch&per_page=100')->assertOk()->json('data');

        $labels = collect($data)->pluck('subject.id');
        $this->assertTrue($labels->contains($branchA->uuid));
        $this->assertFalse($labels->contains($branchB->uuid));
        $this->assertArrayNotHasKey('tenant', $data[0], 'Trường tenant chỉ dành cho Platform Admin');
    }

    public function test_response_shape_includes_who_what_when_where(): void
    {
        [, $owner] = $this->makeTenant();
        $this->spa()->actingAs($owner)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'])
            ->postJson('/api/v1/branches', ['code' => 'API-1', 'name' => 'Chi nhánh API'])->assertCreated();

        $row = $this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=branch&action=created&q=API-1')
            ->assertOk()->json('data.0');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $row['occurred_at']);
        $this->assertSame('success', $row['result']);
        $this->assertSame($owner->uuid, $row['actor']['id']);
        $this->assertSame('user', $row['actor']['type']);
        $this->assertSame('branch', $row['subject']['type']);
        $this->assertSame('API-1 - Chi nhánh API', $row['subject']['label']);
        $this->assertSame('API-1 - Chi nhánh API', $row['branch']);
        $this->assertSame('127.0.0.1', $row['ip_address']);
        $this->assertSame('Chrome trên Windows', $row['device']);
        $this->assertSame('API-1', $row['after']['code']);
        $this->assertNotEmpty($row['request_id']);
        $this->assertArrayNotHasKey('tenant_id', $row);
        $this->assertArrayNotHasKey('actor_id', $row);
    }

    public function test_filters(): void
    {
        [$tenant, $owner, $branch] = $this->makeTenant();
        $admin = $this->makeUserIn($tenant, ['admin']);

        $this->spa()->actingAs($admin)->patchJson('/api/v1/branches/'.$branch->uuid, ['name' => 'Do admin sửa'])->assertOk();
        $this->spa()->actingAs($owner)->postJson('/api/v1/roles', [
            'name' => 'X', 'permissions' => [['key' => 'report.report.view', 'scope' => 'own']],
        ])->assertCreated();

        $get = fn (string $qs) => collect($this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?per_page=100&'.$qs)->assertOk()->json('data'));

        $byAdmin = $get('actor='.$admin->uuid);
        $this->assertNotEmpty($byAdmin);
        $this->assertSame([$admin->uuid], $byAdmin->pluck('actor.id')->unique()->values()->all());

        $this->assertSame(['rbac'], $get('module=rbac&action=role_permissions_changed')->pluck('module')->unique()->values()->all());
        $this->assertSame(['updated'], $get('subject_type=branch&subject_id='.$branch->uuid.'&action=updated')->pluck('action')->unique()->values()->all());
        $this->assertTrue($get('result=denied')->isEmpty());
        $this->assertNotEmpty($get('q=do+admin'), 'Tìm theo nhãn người thực hiện/đối tượng');
        $this->assertTrue($get('q=khong-co-chuoi-nay')->isEmpty());
        $this->assertNotEmpty($get('branch='.$branch->uuid));
    }

    public function test_date_filters_use_utc_and_date_only_upper_bound_is_end_of_day(): void
    {
        [, $owner] = $this->makeTenant();
        $this->spa()->actingAs($owner)->postJson('/api/v1/branches', ['code' => 'NGAY-1', 'name' => 'Ngày'])->assertCreated();
        $today = now('UTC')->format('Y-m-d');

        $count = fn (string $qs) => count($this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=branch&action=created&'.$qs)->assertOk()->json('data'));

        $this->assertGreaterThan(0, $count("from=$today&to=$today"));
        $this->assertSame(0, $count('to='.now('UTC')->subDays(2)->format('Y-m-d')));
        $this->assertSame(0, $count('from='.now('UTC')->addDays(2)->format('Y-m-d')));

        $this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?from=2026-12-31&to=2026-01-01')->assertStatus(422);
    }

    public function test_cursor_pagination_walks_every_row_exactly_once_newest_first(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $this->platform(fn () => Branch::factory()->count(5)->create(['tenant_id' => $tenant->id]));
        $expected = $this->auditCount(['tenant_id' => $tenant->id, 'module' => 'branch', 'action' => 'created']);

        $url = '/api/v1/audit-logs?module=branch&action=created&per_page=2';
        $seen = [];
        $pages = 0;

        while ($url !== null && $pages++ < 20) {
            $response = $this->spa()->actingAs($owner)->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            array_push($seen, ...collect($response->json('data'))->pluck('id')->all());
            $url = $response->json('links.next');
        }

        $this->assertCount($expected, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'Không trùng lặp giữa các trang');

        $times = collect($this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?module=branch&per_page=100')->json('data'))->pluck('occurred_at');
        $this->assertSame($times->sortDesc()->values()->all(), $times->all(), 'Mới nhất trước');
    }

    public function test_viewing_requires_the_audit_permission(): void
    {
        [$tenant] = $this->makeTenant();

        foreach (['sales', 'warehouse', 'accountant', 'delivery'] as $role) {
            $user = $this->makeUserIn($tenant, [$role]);
            $this->spa()->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
        }
    }

    public function test_anonymous_users_are_rejected(): void
    {
        $this->spa()->getJson('/api/v1/audit-logs')->assertUnauthorized();
        $this->spa()->getJson('/api/v1/platform/audit-logs')->assertUnauthorized();
    }

    public function test_branch_scope_only_sees_logs_tied_to_own_branches(): void
    {
        [$tenant, , $branch1] = $this->makeTenant();
        $branch2 = $this->platform(fn () => Branch::factory()->create(['tenant_id' => $tenant->id]));
        $manager = $this->makeUserIn($tenant, ['branch_manager'], $branch1);

        $data = collect($this->spa()->actingAs($manager)->getJson('/api/v1/audit-logs?per_page=100')->assertOk()->json('data'));

        $this->assertNotEmpty($data);
        $this->assertTrue($data->contains(fn ($r) => $r['subject']['id'] === $branch1->uuid));
        $this->assertFalse($data->contains(fn ($r) => $r['subject']['id'] === $branch2->uuid));
        $this->assertFalse($data->contains(fn ($r) => $r['branch'] === null), 'Log không gắn chi nhánh không thuộc phạm vi branch');
    }

    public function test_tenants_never_see_platform_level_or_other_tenants_events(): void
    {
        [, $ownerA] = $this->makeTenant();
        [, $ownerB] = $this->makeTenant();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ke-tan-cong@example.com', 'password' => 'x'])->assertUnauthorized();
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $ownerB->email, 'password' => 'sai'])->assertUnauthorized();

        $data = collect($this->spa()->actingAs($ownerA)->getJson('/api/v1/audit-logs?action=login_failed&per_page=100')->assertOk()->json('data'));

        $this->assertTrue($data->isEmpty());
        $this->assertStringNotContainsString('ke-tan-cong', json_encode($this->spa()->actingAs($ownerA)->getJson('/api/v1/audit-logs?per_page=100')->json()));
    }

    public function test_owner_sees_failed_logins_against_their_own_users(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $staff = $this->makeUserIn($tenant, ['sales']);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'sai'])->assertUnauthorized();

        $data = collect($this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?action=login_failed')->assertOk()->json('data'));

        $this->assertCount(1, $data);
        $this->assertSame('invalid_credentials', $data[0]['reason']);
        $this->assertSame($staff->uuid, $data[0]['subject']['id']);
    }

    // --- Platform -------------------------------------------------------------------------

    public function test_platform_admin_sees_all_tenants_and_platform_events_with_tenant_info(): void
    {
        [$tenantA, $ownerA] = $this->makeTenant(tenant: ['name' => 'Cửa hàng A']);
        [$tenantB] = $this->makeTenant(tenant: ['name' => 'Cửa hàng B']);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'la@example.com', 'password' => 'x'])->assertUnauthorized();
        $admin = $this->makePlatformAdmin();

        $all = collect($this->spa()->actingAs($admin)->getJson('/api/v1/platform/audit-logs?per_page=100')->assertOk()->json('data'));
        $tenantNames = $all->pluck('tenant.name')->unique()->filter()->values()->all();
        $this->assertEqualsCanonicalizing(['Cửa hàng A', 'Cửa hàng B'], $tenantNames);
        $this->assertTrue($all->contains(fn ($r) => $r['action'] === 'login_failed' && $r['tenant'] === null));

        $onlyA = collect($this->spa()->actingAs($admin)->getJson('/api/v1/platform/audit-logs?per_page=100&tenant='.$tenantA->uuid)->json('data'));
        $this->assertSame(['Cửa hàng A'], $onlyA->pluck('tenant.name')->unique()->values()->all());

        $none = collect($this->spa()->actingAs($admin)->getJson('/api/v1/platform/audit-logs?per_page=100&tenant=none')->json('data'));
        $this->assertTrue($none->every(fn ($r) => $r['tenant'] === null));
        $this->assertTrue($none->contains(fn ($r) => $r['action'] === 'login_failed'));
    }

    public function test_platform_admin_actions_are_attributed_to_them(): void
    {
        $admin = $this->makePlatformAdmin(['email' => 'root@agrihub.vn']);
        [$tenant] = $this->makeTenant();

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'root@agrihub.vn', 'password' => 'password'])->assertOk();

        $login = $this->lastAudit(['action' => 'login', 'actor_id' => $admin->id]);
        $this->assertNull($login->tenant_id);
        $this->assertSame($admin->uuid, $login->actor_uuid);

        // Platform Admin sửa dữ liệu một Tenant: log gắn Tenant đó và ghi tên người sửa.
        $this->platform(function () use ($admin, $tenant) {
            $this->actingAs($admin);
            $tenant->forceFill(['name' => 'Tên mới do Super Admin'])->save();
        });

        $log = $this->lastAudit(['module' => 'tenant', 'action' => 'updated']);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->uuid, $log->actor_uuid);
        $this->assertSame('Tên mới do Super Admin', $log->after['name']);
    }

    public function test_tenant_users_cannot_use_the_platform_endpoint(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->getJson('/api/v1/platform/audit-logs')->assertForbidden();
    }

    public function test_viewing_the_log_does_not_create_log_noise(): void
    {
        [, $owner] = $this->makeTenant();
        $before = $this->auditCount();

        $this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs')->assertOk();

        $this->assertSame($before, $this->auditCount());
    }

    public function test_validation_of_filters(): void
    {
        [, $owner] = $this->makeTenant();

        $this->spa()->actingAs($owner)->getJson('/api/v1/audit-logs?result=maybe&actor=khong-phai-uuid&per_page=1000')
            ->assertStatus(422)->assertJsonStructure(['errors' => ['result', 'actor', 'per_page']]);
    }

    public function test_request_id_is_returned_and_included_in_errors(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'client-req-0001'])->assertHeader('X-Request-Id', 'client-req-0001');

        $generated = $this->getJson('/api/v1/health', ['X-Request-Id' => 'x y!'])->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $generated);

        $this->getJson('/api/v1/branches', ['X-Request-Id' => 'client-req-0002'])
            ->assertUnauthorized()->assertJsonPath('request_id', 'client-req-0002');
    }

    public function test_logger_can_write_when_called_from_a_tenant_context(): void
    {
        [$tenant] = $this->makeTenant();

        app(TenantContext::class)->runForTenant($tenant->id, function () {
            app(AuditLogger::class)->record('test', 'in_tenant_ctx', actor: null);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'in_tenant_ctx')->count());
        });

        $this->assertSame($tenant->id, $this->lastAudit(['action' => 'in_tenant_ctx'])->tenant_id);
    }
}
