<?php

namespace Tests\Feature\Console;

use App\Services\Audit\AuditPartitions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

class MaintainAuditLogsTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private function oldPartition(int $monthsAgo = 40): string
    {
        return app(AuditPartitions::class)->ensure(Carbon::now('UTC')->startOfMonth()->subMonths($monthsAgo));
    }

    public function test_creates_missing_future_partitions(): void
    {
        $partitions = app(AuditPartitions::class);
        $name = $partitions->nameFor(Carbon::now('UTC')->startOfMonth()->addMonths(2));
        DB::statement("DROP TABLE {$name}");

        $this->artisan('agrihub:audit-maintain')->assertSuccessful();

        $this->assertTrue($partitions->exists($name));
    }

    public function test_purge_is_disabled_by_default_so_nothing_is_deleted(): void
    {
        $old = $this->oldPartition();

        $this->artisan('agrihub:audit-maintain')->expectsOutputToContain('TẮT')->assertSuccessful();

        $this->assertTrue(app(AuditPartitions::class)->exists($old));
    }

    public function test_purge_drops_expired_partitions_when_enabled_and_audits_the_deletion(): void
    {
        config(['audit.purge_enabled' => true, 'audit.retention_months' => 24]);
        $old = $this->oldPartition();

        $this->artisan('agrihub:audit-maintain')->expectsOutputToContain($old)->assertSuccessful();

        $this->assertFalse(app(AuditPartitions::class)->exists($old));
        $log = $this->lastAudit(['module' => 'audit', 'action' => 'partitions_dropped']);
        $this->assertContains($old, $log->metadata['partitions']);
        $this->assertSame(24, $log->metadata['retention_months']);
    }

    public function test_dry_run_changes_nothing(): void
    {
        config(['audit.purge_enabled' => true, 'audit.retention_months' => 24]);
        $old = $this->oldPartition();
        $partitions = app(AuditPartitions::class);
        $future = $partitions->nameFor(Carbon::now('UTC')->startOfMonth()->addMonths(3));
        DB::statement("DROP TABLE {$future}");

        $this->artisan('agrihub:audit-maintain --dry-run')->expectsOutputToContain($old)->assertSuccessful();

        $this->assertTrue($partitions->exists($old));
        $this->assertFalse($partitions->exists($future), 'dry-run không được tạo phân vùng');
        $this->assertSame(0, $this->auditCount(['action' => 'partitions_dropped']));
    }

    public function test_refuses_to_purge_when_retention_is_below_the_safety_floor(): void
    {
        config(['audit.purge_enabled' => true, 'audit.retention_months' => 3]);
        $old = $this->oldPartition(6);

        $this->artisan('agrihub:audit-maintain')->assertFailed();

        $this->assertTrue(app(AuditPartitions::class)->exists($old));
    }

    public function test_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'agrihub:audit-maintain')));
    }
}
