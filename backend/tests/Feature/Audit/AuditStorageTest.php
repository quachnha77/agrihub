<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditPartitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Tính bất biến, phân vùng, retention và cô lập Tenant của bảng audit_logs ở tầng database. */
class AuditStorageTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- Append-only ----------------------------------------------------------------------

    public function test_audit_rows_cannot_be_updated_or_deleted_even_in_platform_mode(): void
    {
        [$tenant] = $this->makeTenant();
        $row = $this->auditRows(['tenant_id' => $tenant->id])->first();

        foreach ([
            fn () => DB::table('audit_logs')->where('id', $row->id)->update(['reason' => 'giả mạo']),
            fn () => DB::table('audit_logs')->where('id', $row->id)->delete(),
            fn () => DB::table('audit_logs')->truncate(),
        ] as $attempt) {
            try {
                // Savepoint để transaction của test vẫn dùng được sau lỗi.
                $this->platform(fn () => DB::transaction($attempt));
                $this->fail('Thao tác sửa/xóa audit log lẽ ra phải bị chặn.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertNull($this->auditRows(['id' => $row->id])->first()->reason);
    }

    public function test_the_eloquent_model_refuses_writes(): void
    {
        $this->makeTenant();
        $log = $this->platform(fn () => AuditLog::query()->first());

        $this->expectException(LogicException::class);
        $this->platform(fn () => $log->forceFill(['reason' => 'x'])->save());
    }

    public function test_the_eloquent_model_refuses_deletes(): void
    {
        $this->makeTenant();
        $log = $this->platform(fn () => AuditLog::query()->first());

        $this->expectException(LogicException::class);
        $log->delete();
    }

    // --- Phân vùng ------------------------------------------------------------------------

    public function test_current_and_upcoming_partitions_exist_after_migration(): void
    {
        $partitions = app(AuditPartitions::class);

        $this->assertGreaterThanOrEqual(4, count($partitions->all()));
        $this->assertTrue($partitions->exists($partitions->nameFor(Carbon::now('UTC'))));
        $this->assertTrue($partitions->exists($partitions->nameFor(Carbon::now('UTC')->addMonths(3))));
    }

    public function test_a_missing_partition_is_created_on_demand_without_losing_the_event(): void
    {
        Carbon::setTestNow(Carbon::create(2031, 5, 15, 10, 30, 0, 'UTC'));
        $partitions = app(AuditPartitions::class);
        $this->assertFalse($partitions->exists('audit_logs_y2031m05'));

        $this->platform(fn () => app(AuditLogger::class)->record('test', 'future_event'));

        $this->assertTrue($partitions->exists('audit_logs_y2031m05'));
        $row = $this->lastAudit(['action' => 'future_event']);
        $this->assertStringStartsWith('2031-05-15 10:30:00', $row->occurred_at);
    }

    public function test_on_demand_partitions_are_also_protected(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $partitions = app(AuditPartitions::class);
        $name = $partitions->ensure(Carbon::create(2033, 1, 1, 0, 0, 0, 'UTC'));

        $this->platform(fn () => DB::table($name)->insert([
            'tenant_id' => $tenantB->id, 'actor_type' => 'system', 'module' => 't', 'action' => 'a', 'result' => 'success',
            'occurred_at' => '2033-01-10 00:00:00',
        ]));

        // Truy cập trực tiếp phân vùng cũng bị RLS giới hạn.
        app(TenantContext::class)->runForTenant($tenantA->id, fn () => $this->assertSame(0, DB::table($name)->count()));
        $this->assertSame(0, DB::table($name)->count(), 'Không có context => không thấy gì');
        $this->assertSame(1, $this->platform(fn () => DB::table($name)->count()));

        try {
            $this->platform(fn () => DB::transaction(fn () => DB::statement("TRUNCATE {$name}")));
            $this->fail('TRUNCATE phân vùng phải bị chặn.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_ensure_ahead_is_idempotent(): void
    {
        $partitions = app(AuditPartitions::class);
        DB::statement('DROP TABLE '.$partitions->nameFor(Carbon::now('UTC')->addMonths(3)));

        $this->assertCount(1, $partitions->ensureAhead(3));
        $this->assertSame([], $partitions->ensureAhead(3));
    }

    public function test_purge_drops_only_whole_partitions_older_than_retention(): void
    {
        $partitions = app(AuditPartitions::class);
        $old = $partitions->ensure(Carbon::now('UTC')->startOfMonth()->subMonths(30));
        $edge = $partitions->ensure(Carbon::now('UTC')->startOfMonth()->subMonths(24)); // đúng mốc: được giữ
        $recent = $partitions->ensure(Carbon::now('UTC')->startOfMonth()->subMonths(2));

        $this->assertSame([$old], $partitions->purge(24, dryRun: true));
        $this->assertTrue($partitions->exists($old), 'dry-run không được xóa');

        $this->assertSame([$old], $partitions->purge(24));
        $this->assertFalse($partitions->exists($old));
        foreach ([$edge, $recent, $partitions->nameFor(Carbon::now('UTC'))] as $keep) {
            $this->assertTrue($partitions->exists($keep), $keep);
        }
    }

    // --- Cô lập Tenant (RLS) --------------------------------------------------------------

    public function test_rls_hides_other_tenants_and_platform_level_events(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();
        $this->platform(fn () => app(AuditLogger::class)->record('test', 'platform_level', actor: null));
        $ctx = app(TenantContext::class);

        $this->assertSame(0, DB::table('audit_logs')->count(), 'Không có context => không thấy gì');

        $ctx->runForTenant($tenantA->id, function () use ($tenantA) {
            $tenantIds = DB::table('audit_logs')->pluck('tenant_id')->unique()->values()->all();
            $this->assertSame([$tenantA->id], $tenantIds);
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'platform_level')->count());
        });

        $platformSees = $this->platform(fn () => DB::table('audit_logs')->pluck('tenant_id')->unique()->all());
        $this->assertContains($tenantA->id, $platformSees);
        $this->assertContains($tenantB->id, $platformSees);
        $this->assertContains(null, $platformSees);
    }

    public function test_a_tenant_cannot_write_audit_rows_for_another_tenant(): void
    {
        [$tenantA] = $this->makeTenant();
        [$tenantB] = $this->makeTenant();

        $this->expectException(QueryException::class);

        app(TenantContext::class)->runForTenant($tenantA->id, fn () => DB::table('audit_logs')->insert([
            'tenant_id' => $tenantB->id, 'actor_type' => 'system', 'module' => 'x', 'action' => 'y', 'result' => 'success',
        ]));
    }
}
