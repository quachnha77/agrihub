<?php

namespace Tests\Feature\Monitoring;

use App\Models\Notice;
use App\Services\Monitoring\HealthAlerts;
use App\Services\Monitoring\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Cảnh báo hạ tầng theo chuyển trạng thái: báo ngay, nhắc có chu kỳ, báo hồi phục, không báo trùng. */
class HealthAlertsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink(storage_path('framework/monitor-db-down.flag'));
        parent::tearDown();
    }

    /** @return array{components: list<array<string, mixed>>} */
    private function health(string $queue = 'ok', string $disk = 'ok', string $backups = 'ok'): array
    {
        $c = fn (string $key, string $status) => [
            'key' => $key, 'label' => "Thành phần {$key}", 'status' => $status, 'summary' => "Tóm tắt {$key} ({$status})",
            'details' => [['label' => 'Chỉ số', 'value' => '42']],
        ];

        return ['status' => 'x', 'checked_at' => now()->toIso8601String(), 'components' => [$c('queue', $queue), $c('disk', $disk), $c('backups', $backups)]];
    }

    private function alerts(): HealthAlerts
    {
        return app(HealthAlerts::class);
    }

    private function notices(string $type)
    {
        return $this->platform(fn () => Notice::query()->where('type', $type)->orderBy('id')->get());
    }

    public function test_a_component_getting_worse_alerts_every_platform_admin_once(): void
    {
        $one = $this->makePlatformAdmin(['email' => 'one@agrihub.test']);
        $two = $this->makePlatformAdmin(['email' => 'two@agrihub.test']);

        $result = $this->alerts()->process($this->health(queue: 'critical'));

        $this->assertSame(['alerted' => 1, 'recovered' => 0], $result);
        $notices = $this->notices('system.health.queue');
        $this->assertCount(2, $notices);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $notices->pluck('user_id')->all());

        $notice = $notices->first();
        $this->assertSame('critical', $notice->level);
        $this->assertSame('[Hạ tầng] Thành phần queue: NGHIÊM TRỌNG', $notice->title);
        $this->assertStringContainsString('Tóm tắt queue (critical)', $notice->body);
        $this->assertStringContainsString('- Chỉ số: 42', $notice->body);
        $this->assertEquals(['component' => 'queue', 'status' => 'critical'], $notice->data);

        $mail = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(2, $mail);
        $this->assertStringContainsString('[Hạ tầng]', $mail->first()->getOriginalMessage()->getSubject());
    }

    public function test_the_same_problem_is_not_reported_again_until_the_reminder_is_due(): void
    {
        $this->makePlatformAdmin();
        Carbon::setTestNow('2026-10-30 10:00:00');

        $this->assertSame(1, $this->alerts()->process($this->health(queue: 'critical'))['alerted']);
        $this->assertSame(0, $this->alerts()->process($this->health(queue: 'critical'))['alerted']);

        Carbon::setTestNow('2026-10-30 15:59:00'); // < 6 giờ
        $this->assertSame(0, $this->alerts()->process($this->health(queue: 'critical'))['alerted']);

        Carbon::setTestNow('2026-10-30 16:01:00'); // ≥ 6 giờ => nhắc lại
        $this->assertSame(1, $this->alerts()->process($this->health(queue: 'critical'))['alerted']);
        $this->assertCount(2, $this->notices('system.health.queue'));
    }

    public function test_warnings_are_reminded_less_often_than_critical_problems(): void
    {
        $this->makePlatformAdmin();
        Carbon::setTestNow('2026-10-30 10:00:00');

        $this->alerts()->process($this->health(disk: 'warning'));

        Carbon::setTestNow('2026-10-31 09:00:00'); // 23 giờ
        $this->assertSame(0, $this->alerts()->process($this->health(disk: 'warning'))['alerted']);

        Carbon::setTestNow('2026-10-31 10:01:00'); // 24 giờ
        $this->assertSame(1, $this->alerts()->process($this->health(disk: 'warning'))['alerted']);
    }

    public function test_getting_worse_is_reported_at_once_but_getting_better_is_not(): void
    {
        $this->makePlatformAdmin();

        $this->alerts()->process($this->health(disk: 'warning'));
        $this->assertSame(1, $this->alerts()->process($this->health(disk: 'critical'))['alerted'], 'cảnh báo → nghiêm trọng: báo ngay');
        $this->assertSame(0, $this->alerts()->process($this->health(disk: 'warning'))['alerted'], 'nghiêm trọng → cảnh báo: chưa phải lúc báo');

        $this->assertSame('warning', DB::table('system_health_states')->where('component', 'disk')->value('status'));
        $this->assertCount(2, $this->notices('system.health.disk'));
    }

    public function test_recovery_is_announced_once_and_the_state_is_cleared(): void
    {
        $admin = $this->makePlatformAdmin();
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->alerts()->process($this->health(queue: 'critical'));

        Carbon::setTestNow('2026-10-30 10:20:00');
        $this->assertSame(['alerted' => 0, 'recovered' => 1], $this->alerts()->process($this->health()));
        $this->assertSame(['alerted' => 0, 'recovered' => 0], $this->alerts()->process($this->health()), 'chỉ báo hồi phục một lần');

        $recovery = $this->notices('system.health.queue')->last();
        $this->assertSame('info', $recovery->level);
        $this->assertSame($admin->id, $recovery->user_id);
        $this->assertStringContainsString('đã hồi phục', $recovery->title);
        $this->assertStringContainsString('Bị ảnh hưởng từ 17:00 30/10/2026', $recovery->body); // 10:00 UTC = 17:00 giờ Việt Nam

        $state = DB::table('system_health_states')->where('component', 'queue')->first();
        $this->assertSame('ok', $state->status);
        $this->assertNull($state->last_alerted_at);

        // Sự cố mới sau khi hồi phục được báo như lần đầu.
        $this->assertSame(1, $this->alerts()->process($this->health(queue: 'critical'))['alerted']);
    }

    public function test_backups_and_disabled_components_are_not_alerted_here(): void
    {
        $this->makePlatformAdmin();

        $result = $this->alerts()->process($this->health(backups: 'critical', queue: 'disabled'));

        $this->assertSame(['alerted' => 0, 'recovered' => 0], $result);
        $this->assertSame(0, $this->platform(fn () => Notice::query()->count()));
        $this->assertSame(0, DB::table('system_health_states')->count());
    }

    public function test_emergency_alerts_need_only_email_addresses_and_no_database_rows(): void
    {
        $this->makePlatformAdmin();
        config(['monitoring.mail_to' => ['ops@example.com'], 'backup.alerts.mail_to' => ['ops@example.com', 'khac@example.com']]);

        $sent = $this->alerts()->emergency($this->health(queue: 'critical')['components'][0]);

        $this->assertSame(2, $sent, 'hai danh sách địa chỉ được gộp và loại trùng');
        $this->assertSame(0, $this->platform(fn () => Notice::query()->count()));
        $this->assertCount(2, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    // --- lệnh kiểm tra định kỳ -------------------------------------------------------------------

    public function test_the_scheduled_check_does_nothing_while_alerts_are_off(): void
    {
        config(['monitoring.alerts_enabled' => false]);

        $this->artisan('agrihub:monitor:check', ['--scheduled' => true])->expectsOutputToContain('đang tắt')->assertExitCode(0);
    }

    public function test_the_check_command_alerts_once_and_exits_non_zero_when_critical(): void
    {
        // Cấu hình production hợp lý + đĩa rộng, để chỉ còn việc thiếu tín hiệu sống của scheduler và queue worker.
        config([
            'monitoring.alerts_enabled' => true, 'monitoring.thresholds.disk_warn_percent' => 100, 'monitoring.thresholds.disk_critical_percent' => 101,
            'app.debug' => false, 'app.url' => 'https://app.example.com', 'mail.default' => 'smtp', 'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database',
        ]);
        $admin = $this->makePlatformAdmin();
        $this->app['env'] = 'production'; // chưa có tín hiệu sống => scheduler + queue worker nghiêm trọng

        $this->artisan('agrihub:monitor:check', ['--scheduled' => true])->expectsOutputToContain('Cảnh báo mới: 2')->assertExitCode(1);

        $types = $this->platform(fn () => Notice::query()->where('user_id', $admin->id)->pluck('type')->sort()->values()->all());
        $this->assertSame(['system.health.queue_worker', 'system.health.scheduler'], $types);

        $this->artisan('agrihub:monitor:check', ['--scheduled' => true])->expectsOutputToContain('Cảnh báo mới: 0')->assertExitCode(1);
        $this->assertCount(2, $this->platform(fn () => Notice::query()->get()));
    }

    public function test_no_notify_only_prints_the_status(): void
    {
        $this->makePlatformAdmin();
        $this->app['env'] = 'production';

        $this->artisan('agrihub:monitor:check', ['--no-notify' => true])->expectsOutputToContain('Tình trạng hệ thống: critical')->assertExitCode(1);

        $this->assertSame(0, $this->platform(fn () => Notice::query()->count()));
    }

    public function test_when_the_database_itself_is_down_only_an_emergency_email_goes_out_and_not_more_than_every_30_minutes(): void
    {
        config(['monitoring.alerts_enabled' => true, 'monitoring.mail_to' => ['ops@example.com']]);
        $this->makePlatformAdmin();

        $down = ['status' => 'critical', 'checked_at' => now()->toIso8601String(), 'components' => [
            ['key' => 'database', 'label' => 'Cơ sở dữ liệu', 'status' => 'critical', 'summary' => 'Không kiểm tra được (PDOException).', 'details' => []],
        ]];
        $this->mock(SystemHealth::class, fn ($m) => $m->shouldReceive('evaluate')->andReturn($down));

        $this->artisan('agrihub:monitor:check', ['--scheduled' => true])->expectsOutputToContain('gửi email khẩn tới 1 địa chỉ')->assertExitCode(1);
        $this->artisan('agrihub:monitor:check', ['--scheduled' => true])->assertExitCode(1);

        $this->assertCount(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, $this->platform(fn () => Notice::query()->count()), 'không ghi thông báo trong ứng dụng khi database chết');
        $this->assertSame(0, DB::table('system_health_states')->count());
    }
}
