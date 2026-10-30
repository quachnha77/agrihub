<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\Monitoring\QueueHeartbeatJob;
use App\Models\Notice;
use App\Services\Monitoring\Heartbeats;
use App\Services\Monitoring\SystemHealth;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Sức khỏe hạ tầng: các thành phần, ngưỡng, API cho Super Admin và công cụ giám sát, lệnh beat/ping. */
class SystemHealthTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function health(): array
    {
        return app(SystemHealth::class)->evaluate();
    }

    private function part(string $key): array
    {
        return collect($this->health()['components'])->firstWhere('key', $key);
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
    }

    private function beatBoth(): void
    {
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        app(Heartbeats::class)->beat(Heartbeats::QUEUE_WORKER);
    }

    /** Không để đĩa của máy chạy test làm kết quả thay đổi. */
    private function roomyDisk(): void
    {
        config(['monitoring.thresholds.disk_warn_percent' => 100, 'monitoring.thresholds.disk_critical_percent' => 101]);
    }

    // --- cấu trúc và các thành phần ---------------------------------------------------------

    public function test_every_component_is_reported_with_a_status_summary_and_details(): void
    {
        $this->roomyDisk();
        $this->beatBoth();

        $health = $this->health();

        $this->assertSame(['database', 'rls', 'queue', 'scheduler', 'queue_worker', 'disk', 'config', 'backups'], array_column($health['components'], 'key'));
        $this->assertContains($health['status'], ['ok', 'warning', 'critical']);
        $this->assertNotEmpty($health['checked_at']);

        foreach ($health['components'] as $component) {
            $this->assertContains($component['status'], ['ok', 'warning', 'critical', 'disabled']);
            $this->assertNotSame('', $component['summary']);
            $this->assertNotSame('', $component['label']);
        }

        $database = $this->part('database');
        $this->assertSame('ok', $database['status']);
        $this->assertContains('Phiên bản PostgreSQL', array_column($database['details'], 'label'));
    }

    public function test_the_application_role_must_not_bypass_row_level_security(): void
    {
        $rls = $this->part('rls');

        $this->assertSame('ok', $rls['status']);
        $this->assertStringContainsString('RLS có hiệu lực', $rls['summary']);
    }

    public function test_a_bypassrls_connection_is_flagged_critical(): void
    {
        $user = config('backup.platform.username');

        if (! $user) {
            $this->markTestSkipped('Cần BACKUP_DB_USERNAME (role có BYPASSRLS) để mô phỏng cấu hình sai.');
        }

        config(['database.connections.bypass' => array_merge(config('database.connections.pgsql'), ['username' => $user, 'password' => config('backup.platform.password')])]);

        try {
            DB::purge('bypass');
            DB::setDefaultConnection('bypass');
            $rls = $this->part('rls');
        } finally {
            DB::setDefaultConnection('pgsql');
            DB::purge('bypass');
        }

        $this->assertSame('critical', $rls['status']);
        $this->assertStringContainsString('KHÔNG còn được cô lập', $rls['summary']);
    }

    // --- tín hiệu sống ---------------------------------------------------------------------

    public function test_a_missing_heartbeat_is_a_warning_in_development_but_critical_in_production(): void
    {
        $this->assertSame('warning', $this->part('scheduler')['status']);

        $this->production();
        $this->assertSame('critical', $this->part('scheduler')['status']);
        $this->assertStringContainsString('chưa từng gửi tín hiệu sống', $this->part('queue_worker')['summary']);
    }

    public function test_heartbeats_go_stale_in_two_steps(): void
    {
        $this->beatBoth();
        $this->assertSame('ok', $this->part('scheduler')['status']);

        Carbon::setTestNow(now()->addMinutes(6));
        $stale = $this->part('scheduler');
        $this->assertSame('warning', $stale['status']);
        $this->assertStringContainsString('6 phút', $stale['summary']);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame('critical', $this->part('scheduler')['status']);

        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $this->assertSame('ok', $this->part('scheduler')['status']);
    }

    public function test_the_beat_command_records_the_scheduler_and_proves_the_worker_via_the_queue(): void
    {
        $this->artisan('agrihub:monitor:beat')->assertExitCode(0);

        // Hàng đợi của test chạy đồng bộ nên việc kiểm tra được xử lý ngay => worker cũng có tín hiệu.
        $this->assertNotNull(app(Heartbeats::class)->last(Heartbeats::SCHEDULER));
        $this->assertNotNull(app(Heartbeats::class)->last(Heartbeats::QUEUE_WORKER));
        $this->assertSame('ok', $this->part('queue_worker')['status']);
    }

    public function test_the_queue_heartbeat_job_is_unique_so_a_dead_worker_does_not_pile_up_jobs(): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, new QueueHeartbeatJob);
        $this->assertGreaterThanOrEqual(60, (new QueueHeartbeatJob)->uniqueFor);

        (new QueueHeartbeatJob)->handle(app(Heartbeats::class));
        $this->assertNotNull(app(Heartbeats::class)->last(Heartbeats::QUEUE_WORKER));
    }

    // --- hàng đợi ----------------------------------------------------------------------------

    private function job(string $availableAgo, bool $reserved = false): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => now()->sub($availableAgo)->getTimestamp(), 'created_at' => now()->sub($availableAgo)->getTimestamp(),
        ]);
    }

    private function failedJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        }
    }

    public function test_the_database_queue_is_judged_by_its_oldest_waiting_job_backlog_and_failures(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame('ok', $this->part('queue')['status']);

        $this->job('11 minutes');
        $warn = $this->part('queue');
        $this->assertSame('warning', $warn['status']);
        $this->assertStringContainsString('11 phút', $warn['summary']);

        $this->job('31 minutes');
        $this->assertSame('critical', $this->part('queue')['status']);
        $this->assertStringContainsString('worker có thể đã dừng', $this->part('queue')['summary']);
    }

    public function test_jobs_being_processed_and_a_short_backlog_are_not_a_problem(): void
    {
        config(['queue.default' => 'database']);
        $this->job('2 minutes');
        $this->job('3 hours', reserved: true);

        $queue = $this->part('queue');

        $this->assertSame('ok', $queue['status']);
        $this->assertContains(['label' => 'Đang chạy', 'value' => '1'], $queue['details']);
        $this->assertContains(['label' => 'Đang chờ', 'value' => '1'], $queue['details']);
    }

    public function test_failed_jobs_in_the_last_day_raise_the_alarm(): void
    {
        config(['queue.default' => 'database']);

        $this->failedJobs(1);
        $this->assertSame('warning', $this->part('queue')['status']);

        $this->failedJobs(9);
        $this->assertSame('critical', $this->part('queue')['status']);

        DB::table('failed_jobs')->update(['failed_at' => now()->subDays(2)]);
        $this->assertSame('ok', $this->part('queue')['status'], 'lỗi cũ hơn 24 giờ không còn tính');
    }

    public function test_a_large_backlog_is_a_warning(): void
    {
        config(['queue.default' => 'database', 'monitoring.thresholds.queue_backlog_warn' => 3]);
        foreach (range(1, 3) as $i) {
            $this->job('1 minute');
        }

        $this->assertSame('warning', $this->part('queue')['status']);
        $this->assertStringContainsString('3 việc đang chờ', $this->part('queue')['summary']);
    }

    public function test_the_sync_queue_is_fine_in_development_but_a_warning_in_production(): void
    {
        config(['queue.default' => 'sync']);
        $this->assertSame('ok', $this->part('queue')['status']);

        $this->production();
        $this->assertSame('warning', $this->part('queue')['status']);
    }

    // --- đĩa -----------------------------------------------------------------------------------

    public function test_disk_usage_thresholds(): void
    {
        $this->roomyDisk();
        $this->assertSame('ok', $this->part('disk')['status']);

        config(['monitoring.thresholds.disk_warn_percent' => 1, 'monitoring.thresholds.disk_critical_percent' => 101]);
        $warn = $this->part('disk');
        $this->assertSame('warning', $warn['status']);
        $this->assertStringContainsString('đã dùng', $warn['summary']);

        config(['monitoring.thresholds.disk_critical_percent' => 1]);
        $this->assertSame('critical', $this->part('disk')['status']);
    }

    // --- cấu hình --------------------------------------------------------------------------------

    public function test_unsafe_production_settings_are_reported_and_development_is_left_alone(): void
    {
        config(['app.debug' => true, 'mail.default' => 'log', 'app.url' => 'http://localhost']);
        $this->assertSame('ok', $this->part('config')['status'], 'ngoài production không kiểm tra');

        $this->production();
        $config = $this->part('config');

        $this->assertSame('critical', $config['status']);
        $this->assertStringContainsString('APP_DEBUG đang bật ở production', $config['summary']);
        $this->assertStringContainsString('MAIL_MAILER đang là "log"', $config['summary']);
        $this->assertStringContainsString('APP_URL không dùng https', $config['summary']);

        config(['app.debug' => false, 'mail.default' => 'smtp', 'app.url' => 'https://app.example.com', 'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database']);
        $this->assertSame('ok', $this->part('config')['status']);
        $this->assertStringContainsString('Cấu hình production hợp lý', $this->part('config')['summary']);
    }

    // --- tổng hợp ---------------------------------------------------------------------------------

    public function test_the_overall_status_is_the_worst_component_and_disabled_ones_do_not_count(): void
    {
        $this->roomyDisk();
        $this->beatBoth();
        $this->assertSame('ok', $this->health()['status']);
        $this->assertSame('disabled', $this->part('backups')['status']);

        config(['queue.default' => 'database']);
        $this->job('11 minutes');
        $this->assertSame('warning', $this->health()['status']);

        $this->failedJobs(10);
        $this->assertSame('critical', $this->health()['status']);
    }

    public function test_a_component_that_cannot_be_checked_is_critical_without_leaking_details(): void
    {
        $this->mock(Heartbeats::class, fn ($m) => $m->shouldReceive('last')->andThrow(new RuntimeException('password=hunter2 host=10.0.0.5')));

        $scheduler = $this->part('scheduler');

        $this->assertSame('critical', $scheduler['status']);
        $this->assertStringContainsString('RuntimeException', $scheduler['summary']);
        $this->assertStringNotContainsString('hunter2', json_encode($this->health()));
        $this->assertStringNotContainsString('10.0.0.5', json_encode($this->health()));
        $this->assertSame('ok', $this->part('database')['status'], 'một thành phần lỗi không làm hỏng các thành phần khác');
    }

    // --- API -------------------------------------------------------------------------------------------

    public function test_only_super_admins_can_read_the_health_page_data(): void
    {
        $admin = $this->makePlatformAdmin(['password' => 'Mat-khau-cu-2026']);
        [, $owner] = $this->makeTenant([], ['password' => 'Mat-khau-cu-2026']);

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/health')->assertOk()
            ->assertJsonStructure(['data' => ['status', 'checked_at', 'components' => [['key', 'label', 'status', 'summary', 'details']]]]);

        $this->freshBrowser()->spa()->actingAs($owner)->getJson('/api/v1/platform/health')->assertForbidden();
        $this->freshBrowser()->spa()->getJson('/api/v1/platform/health')->assertUnauthorized();
    }

    public function test_the_monitoring_endpoint_needs_a_token_and_reports_only_the_overall_state(): void
    {
        $this->getJson('/api/v1/health/monitor')->assertNotFound(); // chưa cấu hình mã => endpoint không tồn tại

        config(['monitoring.token' => 'ma-bi-mat-rat-dai-0123456789']);
        $this->getJson('/api/v1/health/monitor')->assertUnauthorized();
        $this->getJson('/api/v1/health/monitor', ['Authorization' => 'Bearer sai-ma'])->assertUnauthorized();
        $this->getJson('/api/v1/health/monitor?token=ma-bi-mat-rat-dai-0123456789')->assertUnauthorized(); // không nhận mã qua URL (lọt vào log)

        $this->roomyDisk();
        $this->beatBoth();

        $ok = $this->getJson('/api/v1/health/monitor', ['Authorization' => 'Bearer ma-bi-mat-rat-dai-0123456789'])->assertOk();
        $this->assertContains($ok->json('status'), ['ok', 'warning']);
        $this->assertSame(['status', 'checked_at', 'failing'], array_keys($ok->json()), 'không lộ chi tiết nội bộ');

        $this->getJson('/api/v1/health/monitor', ['X-Monitor-Token' => 'ma-bi-mat-rat-dai-0123456789'])->assertOk();
    }

    public function test_the_monitoring_endpoint_returns_503_when_critical_so_uptime_tools_alert(): void
    {
        config(['monitoring.token' => 'ma-bi-mat-rat-dai-0123456789']);
        $this->production(); // chưa có tín hiệu sống nào ở production => nghiêm trọng

        $response = $this->getJson('/api/v1/health/monitor', ['Authorization' => 'Bearer ma-bi-mat-rat-dai-0123456789'])
            ->assertStatus(503)->assertJsonPath('status', 'critical');

        $this->assertContains('scheduler', $response->json('failing'));
        $this->assertContains('queue_worker', $response->json('failing'));
    }

    // --- ping ra ngoài -------------------------------------------------------------------------------

    public function test_the_external_ping_is_a_no_op_until_configured(): void
    {
        Http::fake();

        $this->artisan('agrihub:monitor:ping')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_external_ping_is_sent_only_while_the_system_is_not_critical(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        $this->roomyDisk();
        $this->beatBoth();
        config(['monitoring.ping_url' => 'https://hc-ping.example/abc']);

        $this->artisan('agrihub:monitor:ping')->assertExitCode(0);
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.example/abc');
    }

    public function test_when_critical_the_ping_goes_silent_or_calls_the_failure_url(): void
    {
        Http::fake(['*' => Http::response('OK')]);
        config(['monitoring.ping_url' => 'https://hc-ping.example/abc']);
        $this->production(); // không có tín hiệu sống => nghiêm trọng

        $this->artisan('agrihub:monitor:ping')->expectsOutputToContain('không gửi tín hiệu còn sống')->assertExitCode(1);
        Http::assertNothingSent();

        config(['monitoring.ping_fail_url' => 'https://hc-ping.example/abc/fail']);
        $this->artisan('agrihub:monitor:ping')->assertExitCode(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.example/abc/fail');
    }

    public function test_an_unreachable_monitoring_service_never_crashes_the_scheduler(): void
    {
        Http::fake(['*' => Http::response('lỗi', 500)]);
        $this->roomyDisk();
        $this->beatBoth();
        config(['monitoring.ping_url' => 'https://hc-ping.example/abc']);

        $this->artisan('agrihub:monitor:ping')->expectsOutputToContain('Không gửi được tín hiệu')->assertExitCode(1);
    }

    public function test_notices_table_is_untouched_by_reading_health(): void
    {
        $this->health();

        $this->assertSame(0, $this->platform(fn () => Notice::query()->count()));
    }
}
