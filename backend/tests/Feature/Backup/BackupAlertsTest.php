<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlert;
use App\Services\Backup\BackupException;
use App\Services\Backup\PlatformBackupMonitor;
use App\Services\Backup\PlatformBackupService;
use App\Services\Backup\PlatformDump;
use App\Services\Backup\TenantExporter;
use App\Services\Notifications\AlertService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\Concerns\UsesBackupTables;
use Tests\TestCase;

/** Thông báo về trạng thái sao lưu: giám sát định kỳ (quá hạn...), sự kiện (thất bại...) và hộp thông báo của người dùng. */
class BackupAlertsTest extends TestCase
{
    use AdministersBackups, CreatesTenants, ReadsAuditLog, RefreshDatabase, UsesBackupTables;

    private const PASSWORD = 'Mat-khau-cu-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupTables();
        config(['backup.platform.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- trợ giúp -------------------------------------------------------------------------

    private function admin(array $attributes = []): User
    {
        return $this->makePlatformAdmin($attributes + ['password' => self::PASSWORD]);
    }

    /** Tạo bản ghi sao lưu toàn hệ thống; các mốc thời gian tính bằng "bao lâu trước". */
    private function platformBackup(string $status = Backup::STATUS_COMPLETED, string $age = '2 hours', array $attributes = []): Backup
    {
        return $this->platform(function () use ($status, $age, $attributes) {
            $at = now()->sub($age);
            $backup = new Backup($attributes + [
                'scope' => Backup::SCOPE_PLATFORM, 'kind' => Backup::KIND_AUTO, 'status' => $status, 'disk' => 'backups', 'format_version' => 1,
                'created_at' => $at, 'updated_at' => $at, 'completed_at' => $at,
            ]);
            $backup->save();

            return $backup;
        });
    }

    private function inbox(User $user, string $query = ''): TestResponse
    {
        return $this->freshBrowser()->spa()->actingAs($user)->getJson('/api/v1/notifications'.$query);
    }

    private function notices(?User $user = null)
    {
        return $this->platform(fn () => Notice::query()->when($user, fn ($q) => $q->where('user_id', $user->id))->orderBy('id')->get());
    }

    /** @return list<array{to: list<string>, subject: string}> */
    private function sentMail(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => [
            'to' => array_map(fn ($a) => $a->getAddress(), $m->getEnvelope()->getRecipients()),
            'subject' => $m->getOriginalMessage()->getSubject(),
        ])->all();
    }

    private function monitor(): PlatformBackupMonitor
    {
        return app(PlatformBackupMonitor::class);
    }

    private function codes(array $status): array
    {
        return array_column($status['issues'], 'code');
    }

    // --- giám sát định kỳ -------------------------------------------------------------------

    public function test_nothing_is_evaluated_or_sent_when_platform_backup_is_disabled(): void
    {
        config(['backup.platform.enabled' => false]);
        $this->admin(['created_at' => now()->subDays(30)]);

        $status = $this->monitor()->status();

        $this->assertSame('disabled', $status['state']);
        $this->assertSame([], $status['issues']);
        $this->assertSame(0, $this->monitor()->check()['notified']);
        $this->assertCount(0, $this->notices());

        $this->artisan('agrihub:backup:check', ['--scheduled' => true])->expectsOutputToContain('tắt')->assertExitCode(0);
    }

    public function test_a_brand_new_system_with_no_backup_yet_is_not_overdue(): void
    {
        $this->admin();

        $this->assertSame('ok', $this->monitor()->status()['state']);
    }

    public function test_an_old_system_without_any_backup_is_critical_and_reminders_are_deduplicated(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(3)]);

        $status = $this->monitor()->status();
        $this->assertSame('critical', $status['state']);
        $this->assertSame(['backup_overdue'], $this->codes($status));
        $this->assertNull($status['last_completed']);

        $this->assertSame(1, $this->monitor()->check()['notified']);
        $notice = $this->notices($admin)->sole();
        $this->assertSame('backup.platform.backup_overdue', $notice->type);
        $this->assertSame('critical', $notice->level);
        $this->assertNull($notice->tenant_id);
        $this->assertCount(1, $this->sentMail());

        // Chạy lại mỗi giờ nhưng cùng một vấn đề chỉ báo 1 lần trong 24 giờ.
        $this->assertSame(0, $this->monitor()->check()['notified']);
        $this->assertCount(1, $this->notices($admin));

        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, $this->monitor()->check()['notified']);
        $this->assertCount(2, $this->notices($admin));
    }

    public function test_a_healthy_setup_reports_ok_with_the_last_backup_details(): void
    {
        $this->admin(['created_at' => now()->subDays(30)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '2 hours', [
            'size_bytes' => 1234, 'verify_status' => 'passed', 'verified_at' => now()->subDay(), 'downloaded_at' => now()->subHour(),
        ]);

        $status = $this->monitor()->status();

        $this->assertSame('ok', $status['state']);
        $this->assertSame([], $status['issues']);
        $this->assertSame(2, $status['last_completed']['age_hours']);
        $this->assertSame(1234, $status['last_completed']['size_bytes']);
        $this->assertSame('passed', $status['last_completed']['verify_status']);
        $this->assertSame('passed', $status['last_verified']['status']);
        $this->assertNotNull($status['last_downloaded_at']);
        $this->assertSame(['backup_at' => '01:30', 'prune_at' => '03:30', 'verify_day' => 0, 'verify_at' => '04:30'], $status['schedule']);
        $this->assertSame(0, $this->monitor()->check()['notified']);
    }

    public function test_a_stale_last_backup_is_overdue(): void
    {
        $this->admin(['created_at' => now()->subDays(30)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '40 hours', ['verify_status' => 'passed', 'verified_at' => now()->subDay(), 'downloaded_at' => now()]);

        $status = $this->monitor()->status();

        $this->assertSame('critical', $status['state']);
        $this->assertStringContainsString('40 giờ', $status['issues'][0]['message']);
        $this->assertSame(1, $this->monitor()->check()['notified']);
    }

    public function test_a_failed_latest_attempt_is_shown_but_not_re_notified_by_the_periodic_check(): void
    {
        $this->admin(['created_at' => now()->subDays(30)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '20 hours', ['verify_status' => 'passed', 'verified_at' => now()->subDay(), 'downloaded_at' => now()]);
        $this->platformBackup(Backup::STATUS_FAILED, '1 hour', ['error' => 'Hết dung lượng đĩa']);

        $status = $this->monitor()->status();

        $this->assertSame('critical', $status['state']);
        $this->assertSame(['last_backup_failed'], $this->codes($status));
        $this->assertSame('failed', $status['last_attempt']['status']);
        // Sự kiện thất bại đã được báo ngay lúc xảy ra; kiểm tra định kỳ không báo trùng.
        $this->assertSame(0, $this->monitor()->check()['notified']);

        // Có bản mới thành công thì hết cảnh báo.
        $this->platformBackup(Backup::STATUS_COMPLETED, '1 minute', ['downloaded_at' => now()]);
        $this->assertSame('ok', $this->monitor()->status()['state']);
    }

    public function test_a_failed_restore_check_is_critical_until_a_later_check_passes(): void
    {
        $this->admin(['created_at' => now()->subDays(30)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '2 hours', [
            'verify_status' => 'failed', 'verified_at' => now()->subHour(), 'verify_error' => 'Số dòng không khớp', 'downloaded_at' => now(),
        ]);

        $status = $this->monitor()->status();
        $this->assertSame(['verify_failed'], $this->codes($status));
        $this->assertStringContainsString('Số dòng không khớp', $status['issues'][0]['message']);

        $this->platformBackup(Backup::STATUS_COMPLETED, '1 minute', ['verify_status' => 'passed', 'verified_at' => now(), 'downloaded_at' => now()]);
        $this->assertSame('ok', $this->monitor()->status()['state']);
    }

    public function test_a_restore_check_that_is_too_old_is_a_warning(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(60)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '15 days', ['verify_status' => 'passed', 'verified_at' => now()->subDays(12)]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '2 hours', ['downloaded_at' => now()]);

        $status = $this->monitor()->status();

        $this->assertSame('warning', $status['state']);
        $this->assertSame(['verify_overdue'], $this->codes($status));
        $this->assertSame(1, $this->monitor()->check()['notified']);
        $this->assertSame('warning', $this->notices($admin)->sole()->level);
    }

    public function test_it_reminds_to_keep_a_copy_outside_the_server_until_one_is_downloaded(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(60)]);
        $old = $this->platformBackup(Backup::STATUS_COMPLETED, '8 days', ['verify_status' => 'passed', 'verified_at' => now()->subDay()]);
        $this->platformBackup(Backup::STATUS_COMPLETED, '2 hours');

        $status = $this->monitor()->status();
        $this->assertSame(['offsite_overdue'], $this->codes($status));
        $this->assertStringContainsString('có bản sao lưu nào được tải về', $status['issues'][0]['message']);
        $this->assertSame(1, $this->monitor()->check()['notified']);
        $this->assertSame('backup.platform.offsite_overdue', $this->notices($admin)->sole()->type);

        // Tải một bản về => hết nhắc.
        $this->platform(fn () => $old->forceFill(['downloaded_at' => now()->subDay()])->save());
        $this->assertSame('ok', $this->monitor()->status()['state']);

        // Quá số ngày kể từ lần tải cuối => nhắc lại; đặt 0 để tắt hẳn.
        $this->platform(fn () => $old->forceFill(['downloaded_at' => now()->subDays(9)])->save());
        $this->assertSame(['offsite_overdue'], $this->codes($this->monitor()->status()));
        config(['backup.alerts.offsite_days' => 0]);
        $this->assertSame('ok', $this->monitor()->status()['state']);
    }

    public function test_stuck_backups_are_marked_failed_and_reported(): void
    {
        $admin = $this->admin();
        $this->platformBackup(Backup::STATUS_RUNNING, '13 hours');

        $this->assertSame(1, app(PlatformBackupService::class)->markInterrupted());

        $notice = $this->notices($admin)->sole();
        $this->assertSame('backup.platform.interrupted', $notice->type);
        $this->assertSame('critical', $notice->level);
        $this->assertSame(0, app(PlatformBackupService::class)->markInterrupted());
        $this->assertCount(1, $this->notices($admin));
    }

    public function test_the_status_endpoint_and_command(): void
    {
        $admin = $this->admin(['created_at' => now()->subDays(3)]);
        [, $owner] = $this->makeTenant(['name' => 'Cửa hàng A'], ['password' => self::PASSWORD]);

        $this->freshBrowser()->spa()->actingAs($admin)->getJson('/api/v1/platform/backups/status')
            ->assertOk()->assertJsonPath('data.state', 'critical')->assertJsonPath('data.issues.0.code', 'backup_overdue')
            ->assertJsonPath('data.thresholds.overdue_hours', 36);
        $this->freshBrowser()->spa()->actingAs($owner)->getJson('/api/v1/platform/backups/status')->assertForbidden();
        $this->freshBrowser()->spa()->getJson('/api/v1/platform/backups/status')->assertUnauthorized();

        // Mã thoát khác 0 khi nghiêm trọng (cho công cụ giám sát bên ngoài); --no-notify không gửi gì.
        $this->artisan('agrihub:backup:check', ['--no-notify' => true])->expectsOutputToContain('backup_overdue')->assertExitCode(1);
        $this->assertCount(0, $this->notices());
        $this->artisan('agrihub:backup:check')->expectsOutputToContain('Đã gửi thông báo')->assertExitCode(1);
        $this->assertCount(1, $this->notices($admin));
    }

    // --- sự kiện: toàn hệ thống --------------------------------------------------------------

    private function failingDump(): void
    {
        $this->mock(PlatformDump::class, fn ($m) => $m->shouldReceive('create')->andThrow(new BackupException('Không kết nối được máy chủ cơ sở dữ liệu.')));
    }

    public function test_a_failed_platform_backup_alerts_every_active_platform_admin_in_app_and_by_email(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        config(['backup.alerts.mail_to' => ['ops@example.com']]);
        $one = $this->admin(['email' => 'one@agrihub.test']);
        $two = $this->admin(['email' => 'two@agrihub.test']);
        $off = $this->admin(['email' => 'off@agrihub.test', 'status' => User::STATUS_DISABLED]);
        [, $owner] = $this->makeTenant(['name' => 'Cửa hàng A']);
        // 18:30 UTC hôm qua = 01:30 sáng nay giờ Việt Nam.
        $this->platformBackup(Backup::STATUS_COMPLETED, '15 hours 30 minutes');
        $this->failingDump();

        $service = app(PlatformBackupService::class);
        $backup = $service->run($service->newRecord(null, Backup::KIND_AUTO));

        $this->assertSame('failed', $backup->status);

        foreach ([$one, $two] as $admin) {
            $notice = $this->notices($admin)->sole();
            $this->assertSame('backup.platform.failed', $notice->type);
            $this->assertSame('critical', $notice->level);
            $this->assertStringContainsString('THẤT BẠI', $notice->title);
            $this->assertStringContainsString('Không kết nối được máy chủ cơ sở dữ liệu.', $notice->body);
            $this->assertStringContainsString('01:30 30/10/2026', $notice->body, 'giờ phải hiển thị theo múi giờ Việt Nam');
            $this->assertSame($backup->uuid, $notice->data['backup']);
        }

        $this->assertCount(0, $this->notices($off));
        $this->assertCount(0, $this->notices($owner));

        $mail = $this->sentMail();
        $this->assertCount(3, $mail);
        $this->assertEqualsCanonicalizing(['one@agrihub.test', 'two@agrihub.test', 'ops@example.com'], array_merge(...array_column($mail, 'to')));
        $this->assertStringContainsString('THẤT BẠI', $mail[0]['subject']);
    }

    public function test_a_failed_platform_backup_with_no_earlier_success_says_so(): void
    {
        $admin = $this->admin();
        $this->failingDump();

        $service = app(PlatformBackupService::class);
        $service->run($service->newRecord(null, Backup::KIND_AUTO));

        $this->assertStringContainsString('chưa có', $this->notices($admin)->sole()->body);
    }

    public function test_a_failed_restore_check_alerts_the_platform_admins(): void
    {
        $admin = $this->admin();
        $content = 'noi-dung';
        $backup = $this->platformBackup(Backup::STATUS_COMPLETED, '1 hour');
        $path = "platform/2026/10/{$backup->uuid}.agbk";
        Storage::disk('backups')->put($path, $content);
        $this->platform(fn () => $backup->forceFill(['path' => $path, 'checksum_sha256' => hash('sha256', $content), 'size_bytes' => strlen($content)])->save());

        $this->mock(PlatformDump::class, function ($m) {
            $m->shouldReceive('createDatabase')->once();
            $m->shouldReceive('restoreInto')->once()->andThrow(new BackupException('pg_restore báo lỗi'));
            $m->shouldReceive('dropDatabase')->once();
        });

        $checked = app(PlatformBackupService::class)->verify($backup);

        $this->assertSame('failed', $checked->verify_status);
        $notice = $this->notices($admin)->sole();
        $this->assertSame('backup.platform.verify_failed', $notice->type);
        $this->assertStringContainsString('pg_restore báo lỗi', $notice->body);
    }

    public function test_a_passing_restore_check_sends_nothing(): void
    {
        $admin = $this->admin();
        $content = 'noi-dung';
        $backup = $this->platformBackup(Backup::STATUS_COMPLETED, '1 hour');
        $path = "platform/2026/10/{$backup->uuid}.agbk";
        Storage::disk('backups')->put($path, $content);
        $this->platform(fn () => $backup->forceFill(['path' => $path, 'checksum_sha256' => hash('sha256', $content), 'size_bytes' => strlen($content)])->save());

        $this->mock(PlatformDump::class, function ($m) {
            $m->shouldReceive('createDatabase')->once();
            $m->shouldReceive('restoreInto')->once()->andReturn(['row_counts' => []]);
            $m->shouldReceive('compare')->once()->andReturn([]);
            $m->shouldReceive('dropDatabase')->once();
        });

        $this->assertSame('passed', app(PlatformBackupService::class)->verify($backup)->verify_status);
        $this->assertCount(0, $this->notices($admin));
    }

    public function test_a_broken_mail_server_never_breaks_the_flow_or_the_in_app_notice(): void
    {
        $admin = $this->admin();

        $sent = app(AlertService::class)->toPlatformAdmins(new ExplodingMailAlert('test', 'critical', 'Tiêu đề', 'Nội dung'));

        $this->assertSame(1, $sent);
        $this->assertSame('Tiêu đề', $this->notices($admin)->sole()->title);
    }

    // --- sự kiện: theo Tenant ---------------------------------------------------------------

    /** @return array{0: Tenant, 1: User} */
    private function shop(string $name): array
    {
        return $this->makeTenant(['name' => $name], ['password' => self::PASSWORD]);
    }

    public function test_the_super_admin_who_requested_a_tenant_backup_is_told_when_it_is_ready(): void
    {
        [, $owner] = $this->shop('Cửa hàng A');
        [, $stranger] = $this->shop('Cửa hàng B');
        $requester = $this->backupAdmin();
        $otherAdmin = $this->admin();

        $this->adminBackup($this->platform(fn () => Tenant::where('name', 'Cửa hàng A')->firstOrFail()));

        $mine = $this->inbox($requester)->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'backup.tenant.completed')->assertJsonPath('data.0.level', 'info')
            ->assertJsonPath('data.0.read_at', null);
        $this->assertStringContainsString('tải về', $mine->json('data.0.body'));
        $this->assertStringContainsString('Cửa hàng A', $mine->json('data.0.body'), 'nêu rõ là của doanh nghiệp nào');

        // Chủ doanh nghiệp không có chức năng sao lưu nên không nhận thông báo; Super Admin khác và doanh nghiệp khác cũng không.
        $this->inbox($owner)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->inbox($stranger)->assertOk()->assertJsonCount(0, 'data');
        $this->inbox($otherAdmin)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame([], $this->sentMail(), 'thông báo của doanh nghiệp chỉ ở trong ứng dụng, không gửi email');
    }

    public function test_the_requester_is_told_when_a_tenant_backup_fails(): void
    {
        [$tenant] = $this->shop('Cửa hàng A');
        $this->mock(TenantExporter::class, fn ($m) => $m->shouldReceive('export')->andThrow(new BackupException('Ổ đĩa đầy')));

        $this->asBackupAdmin()->postJson($this->backupPath($tenant))->assertStatus(202);

        $this->inbox($this->backupAdmin())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'backup.tenant.failed')->assertJsonPath('data.0.level', 'critical')
            ->assertJsonPath('data.0.body', 'Doanh nghiệp Cửa hàng A. Lý do: Ổ đĩa đầy')->assertJsonStructure(['data' => [['id', 'payload' => ['backup']]]]);
    }

    public function test_restore_results_are_reported_and_the_internal_restore_point_is_not(): void
    {
        [$tenant] = $this->shop('Cửa hàng A');
        $backup = $this->adminBackup($tenant);
        $requester = $this->backupAdmin();

        $this->adminRestore($backup)->assertStatus(202);
        $types = collect($this->inbox($requester)->json('data'))->pluck('type')->all();
        // 1 thông báo sao lưu xong + 1 khôi phục xong; bản "điểm quay lại" tự tạo trước khi khôi phục không sinh thông báo riêng.
        $this->assertEqualsCanonicalizing(['backup.tenant.completed', 'backup.restore.completed'], $types);
        $done = collect($this->inbox($requester)->json('data'))->firstWhere('type', 'backup.restore.completed');
        $this->assertStringContainsString('Cửa hàng A', $done['body']);

        // File bị mất => khôi phục thất bại, dữ liệu giữ nguyên, người yêu cầu được báo.
        Storage::disk('backups')->delete($backup->path);
        $this->adminRestore($backup)->assertStatus(202);

        $failed = collect($this->inbox($requester)->json('data'))->firstWhere('type', 'backup.restore.failed');
        $this->assertSame('critical', $failed['level']);
        $this->assertStringContainsString('giữ nguyên', $failed['body']);
        $this->assertStringContainsString('Cửa hàng A', $failed['body']);
    }

    // --- hộp thông báo -------------------------------------------------------------------------

    private function tell(User $user, string $title = 'Tiêu đề', string $level = 'info'): void
    {
        app(AlertService::class)->toUser($user, new SystemAlert('test.notice', $level, $title, 'Nội dung'));
    }

    public function test_each_user_only_sees_and_changes_their_own_notices(): void
    {
        [$tenantA, $a] = $this->shop('Cửa hàng A');
        [, $b] = $this->shop('Cửa hàng B');
        $a2 = $this->makeUserIn($tenantA, [Role::OWNER]);
        $admin = $this->admin();

        $this->tell($a, 'Của A 1');
        $this->tell($a, 'Của A 2');
        $this->tell($a2, 'Của A2');
        $this->tell($b, 'Của B');
        $this->tell($admin, 'Của quản trị');

        $list = $this->inbox($a)->assertOk()->assertJsonPath('unread_count', 2)->assertJsonCount(2, 'data');
        $this->assertSame(['Của A 2', 'Của A 1'], array_column($list->json('data'), 'title'), 'mới nhất trước');
        $this->inbox($b)->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Của B');
        $this->inbox($admin)->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Của quản trị');

        $first = $list->json('data.0.id');
        $otherUsers = $this->inbox($b)->json('data.0.id');

        // Không đánh dấu/đọc được thông báo của người khác (kể cả cùng Tenant hay của quản trị).
        $this->freshBrowser()->spa()->actingAs($a)->postJson("/api/v1/notifications/{$otherUsers}/read")->assertNotFound();
        $this->freshBrowser()->spa()->actingAs($a)->postJson('/api/v1/notifications/'.$this->notices($a2)->sole()->uuid.'/read')->assertNotFound();
        $this->freshBrowser()->spa()->actingAs($a)->postJson('/api/v1/notifications/'.$this->notices($admin)->sole()->uuid.'/read')->assertNotFound();

        $this->freshBrowser()->spa()->actingAs($a)->postJson("/api/v1/notifications/{$first}/read")->assertOk()
            ->assertJsonPath('data.id', $first)->assertJsonPath('data.read_at', fn ($v) => $v !== null);
        $this->inbox($a, '?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.title', 'Của A 1');
        $this->assertNull($this->notices($a2)->sole()->read_at);

        $this->freshBrowser()->spa()->actingAs($a)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
        $this->inbox($a)->assertJsonPath('unread_count', 0);
        $this->inbox($a2)->assertJsonPath('unread_count', 1);

        $this->freshBrowser()->spa()->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->freshBrowser()->spa()->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }

    public function test_row_level_security_hides_notices_from_other_tenants_and_platform_notices_from_every_tenant(): void
    {
        [$tenantA, $a] = $this->shop('Cửa hàng A');
        [$tenantB, $b] = $this->shop('Cửa hàng B');
        $admin = $this->admin();
        $this->tell($a);
        $this->tell($b);
        $this->tell($b);
        $this->tell($admin);

        $context = app(TenantContext::class);

        $this->assertSame(1, $context->runForTenant($tenantA->id, fn () => DB::table('notices')->count()));
        $this->assertSame(2, $context->runForTenant($tenantB->id, fn () => DB::table('notices')->count()));
        $this->assertSame(4, $context->runAsPlatform(fn () => DB::table('notices')->count()));
        $this->assertSame(0, DB::table('notices')->count(), 'không có context => không thấy gì');
        $this->assertSame(0, $context->runForTenant($tenantA->id, fn () => DB::table('notices')->whereNull('tenant_id')->count()));
    }

    public function test_only_valid_levels_are_accepted_by_the_database(): void
    {
        $admin = $this->admin();

        $this->expectException(QueryException::class);

        $this->platform(fn () => DB::table('notices')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $admin->id, 'type' => 'x', 'level' => 'fatal', 'title' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_old_notices_are_pruned(): void
    {
        [, $a] = $this->shop('Cửa hàng A');
        $admin = $this->admin();

        foreach ([$a, $admin] as $user) {
            $this->tell($user, 'mới, chưa đọc');
            $this->tell($user, 'đọc từ lâu');
            $this->tell($user, 'chưa đọc từ rất lâu');
            $this->tell($user, 'đọc gần đây');
        }

        $this->platform(function () {
            Notice::where('title', 'đọc từ lâu')->update(['created_at' => now()->subDays(40), 'read_at' => now()->subDays(39)]);
            Notice::where('title', 'chưa đọc từ rất lâu')->update(['created_at' => now()->subDays(100)]);
            Notice::where('title', 'đọc gần đây')->update(['created_at' => now()->subDays(5), 'read_at' => now()->subDays(4)]);
        });

        $this->artisan('agrihub:notices:prune')->expectsOutputToContain('Đã xóa 4')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(
            ['mới, chưa đọc', 'mới, chưa đọc', 'đọc gần đây', 'đọc gần đây'],
            $this->notices()->pluck('title')->all(),
        );
    }
}

/** Thông báo mà bước dựng email luôn hỏng, để chứng minh lỗi một kênh không làm mất kênh khác. */
final class ExplodingMailAlert extends SystemAlert
{
    public function toMail(object $notifiable): MailMessage
    {
        throw new RuntimeException('Máy chủ mail không phản hồi');
    }
}
