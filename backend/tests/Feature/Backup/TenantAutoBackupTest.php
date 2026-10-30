<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Models\Notice;
use App\Models\RestoreRun;
use App\Models\Tenant;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\Backup\PlatformBackupMonitor;
use App\Services\Backup\TenantExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\Concerns\UsesBackupTables;
use Tests\TestCase;

/** Sao lưu tự động hằng ngày theo Tenant, chính sách giữ bản, cảnh báo khi lỗi và dấu vân tay khóa mã hóa. */
class TenantAutoBackupTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase, UsesBackupTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupTables();
        config(['backup.tenant_auto.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function backups(?Tenant $tenant = null, ?string $kind = null)
    {
        return $this->platform(fn () => Backup::withoutGlobalScopes()->where('scope', 'tenant')
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('id')->get());
    }

    /** Tạo bản ghi (và file) cũ $age trước, để thử chính sách giữ bản. */
    private function oldBackup(Tenant $tenant, string $kind, string $age, string $status = 'completed'): Backup
    {
        return $this->platform(function () use ($tenant, $kind, $age, $status) {
            $at = now()->sub($age);
            $backup = new Backup([
                'scope' => 'tenant', 'kind' => $kind, 'status' => $status, 'disk' => 'backups', 'format_version' => 1,
                'created_at' => $at, 'updated_at' => $at, 'completed_at' => $at,
            ]);
            $backup->tenant_id = $tenant->id;
            $backup->save();

            if ($status === 'completed') {
                $path = "tenants/{$tenant->uuid}/old/{$backup->uuid}.agbk";
                Storage::disk('backups')->put($path, 'x');
                $backup->forceFill(['path' => $path])->save();
            }

            return $backup;
        });
    }

    private function exists(Backup $backup): bool
    {
        return $this->platform(fn () => Backup::withoutGlobalScopes()->whereKey($backup->id)->exists());
    }

    // --- Sao lưu hằng ngày ----------------------------------------------------------------

    public function test_the_daily_command_backs_up_every_tenant_and_records_the_key_fingerprint(): void
    {
        [$a] = $this->makeTenant(['name' => 'Cửa hàng A']);
        [$b] = $this->makeTenant(['name' => 'Cửa hàng B']);
        [$c] = $this->makeTenant(['name' => 'Cửa hàng C']);
        $this->platform(fn () => $c->forceFill(['status' => 'suspended'])->save());

        $this->artisan('agrihub:backup:tenants', ['--scheduled' => true])->expectsOutputToContain('3 doanh nghiệp')->assertExitCode(0);

        foreach ([$a, $b, $c] as $tenant) {
            $backup = $this->backups($tenant)->sole();
            $this->assertSame('auto', $backup->kind);
            $this->assertSame('completed', $backup->status, (string) $backup->error);
            $this->assertSame(app(BackupCrypto::class)->keyFingerprint(), $backup->key_fingerprint);
            $this->assertNull($backup->created_by);
            $this->assertTrue(Storage::disk('backups')->exists($backup->path));
        }

        // Tenant chỉ thấy bản của mình, loại "auto", và không bị báo gì khi thành công.
        $this->assertSame(3, $this->auditCount(['module' => 'backup', 'action' => 'created', 'actor_type' => 'system']));
        $this->assertCount(0, $this->platform(fn () => Notice::query()->get()));
    }

    public function test_the_scheduled_run_is_a_no_op_when_disabled_but_a_manual_run_still_works(): void
    {
        [$a] = $this->makeTenant();
        config(['backup.tenant_auto.enabled' => false]);

        $this->artisan('agrihub:backup:tenants', ['--scheduled' => true])->expectsOutputToContain('tắt')->assertExitCode(0);
        $this->assertCount(0, $this->backups());

        $this->artisan('agrihub:backup:tenants')->assertExitCode(0);
        $this->assertCount(1, $this->backups($a, 'auto'));
    }

    public function test_tenants_being_restored_or_already_backing_up_are_skipped_and_stuck_ones_are_unblocked(): void
    {
        [$restoring] = $this->makeTenant(['name' => 'Đang khôi phục']);
        [$busy] = $this->makeTenant(['name' => 'Đang chạy']);
        [$stuck] = $this->makeTenant(['name' => 'Bị treo']);
        [$free] = $this->makeTenant(['name' => 'Rảnh']);

        $this->platform(fn () => $restoring->forceFill(['restoring_at' => now()])->save());
        $this->oldBackup($busy, 'manual', '1 hour', 'running');
        $this->oldBackup($stuck, 'manual', '13 hours', 'running');

        $this->artisan('agrihub:backup:tenants')->expectsOutputToContain('2 doanh nghiệp; bỏ qua 2')->assertExitCode(0);

        $this->assertCount(0, $this->backups($restoring, 'auto'));
        $this->assertCount(0, $this->backups($busy, 'auto'));
        $this->assertCount(1, $this->backups($free, 'auto'));
        // "stuck" bị coi là gián đoạn nên được sao lưu lại (cũng được tính là Tenant chưa có bản chạy dở).
        $this->assertCount(1, $this->backups($stuck, 'auto'));
        $this->assertSame('failed', $this->backups($stuck, 'manual')->sole()->status);
    }

    // --- Giữ bản --------------------------------------------------------------------------

    public function test_retention_keeps_recent_and_minimum_newest_and_never_touches_other_kinds(): void
    {
        [$recent] = $this->makeTenant(['name' => 'Có bản mới']);
        [$stale] = $this->makeTenant(['name' => 'Lâu không chạy']);

        $fresh = $this->oldBackup($recent, 'auto', '2 days');
        $fresh2 = $this->oldBackup($recent, 'auto', '5 days');
        $oldRecent = $this->oldBackup($recent, 'auto', '29 days');
        $expired = $this->oldBackup($recent, 'auto', '31 days');
        $expiredFailed = $this->oldBackup($recent, 'auto', '40 days', 'failed');
        $manualOld = $this->oldBackup($recent, 'manual', '200 days');
        $uploadOld = $this->oldBackup($recent, 'upload', '200 days');
        $preOld = $this->oldBackup($recent, 'pre_restore', '200 days');

        // Tenant mà lịch đã ngừng chạy: toàn bản cũ => vẫn giữ 3 bản mới nhất.
        $s1 = $this->oldBackup($stale, 'auto', '40 days');
        $s2 = $this->oldBackup($stale, 'auto', '41 days');
        $s3 = $this->oldBackup($stale, 'auto', '42 days');
        $s4 = $this->oldBackup($stale, 'auto', '43 days');
        $s5 = $this->oldBackup($stale, 'auto', '44 days');

        $result = app(BackupService::class)->pruneAuto(dryRun: true);
        $this->assertSame(4, $result['deleted']);
        $this->assertTrue($this->exists($expired), 'chạy thử không xóa gì');

        $result = app(BackupService::class)->pruneAuto();
        $this->assertSame(['kept' => 6, 'deleted' => 4, 'protected' => 0], $result);

        foreach ([$fresh, $fresh2, $oldRecent, $manualOld, $uploadOld, $preOld, $s1, $s2, $s3] as $kept) {
            $this->assertTrue($this->exists($kept), "{$kept->kind} {$kept->created_at} phải được giữ");
        }

        foreach ([$expired, $expiredFailed, $s4, $s5] as $gone) {
            $this->assertFalse($this->exists($gone));
            $this->assertFalse($gone->path !== null && Storage::disk('backups')->exists($gone->path), 'file phải bị xóa cùng bản ghi');
        }
    }

    public function test_a_backup_that_is_the_source_or_restore_point_of_a_restore_is_kept(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $source = $this->oldBackup($tenant, 'auto', '90 days');
        $point = $this->oldBackup($tenant, 'auto', '91 days');
        $other = $this->oldBackup($tenant, 'auto', '92 days');
        $newest = [$this->oldBackup($tenant, 'auto', '1 day'), $this->oldBackup($tenant, 'auto', '2 days'), $this->oldBackup($tenant, 'auto', '3 days')];

        $this->platform(function () use ($tenant, $source, $point, $owner) {
            $run = new RestoreRun(['backup_id' => $source->id, 'pre_backup_id' => $point->id, 'status' => 'completed', 'performed_by' => $owner->id]);
            $run->tenant_id = $tenant->id;
            $run->save();
        });

        $result = app(BackupService::class)->pruneAuto();

        $this->assertSame(2, $result['protected']);
        $this->assertSame(1, $result['deleted']);
        $this->assertTrue($this->exists($source));
        $this->assertTrue($this->exists($point));
        $this->assertFalse($this->exists($other));
        $this->assertCount(3, array_filter($newest, fn ($b) => $this->exists($b)));
    }

    public function test_the_prune_command_covers_tenants_and_respects_the_scheduled_switch(): void
    {
        [$tenant] = $this->makeTenant();
        config(['backup.platform.enabled' => false]);
        $this->oldBackup($tenant, 'auto', '60 days');
        $kept = [$this->oldBackup($tenant, 'auto', '1 day'), $this->oldBackup($tenant, 'auto', '2 days'), $this->oldBackup($tenant, 'auto', '3 days')];

        config(['backup.tenant_auto.enabled' => false]);
        $this->artisan('agrihub:backup:prune', ['--scheduled' => true])->expectsOutputToContain('bỏ qua phần theo Tenant')->assertExitCode(0);
        $this->assertCount(4, $this->backups($tenant));

        config(['backup.tenant_auto.enabled' => true]);
        $this->artisan('agrihub:backup:prune', ['--scheduled' => true])->expectsOutputToContain('đã xóa 1')->assertExitCode(0);
        $this->assertCount(3, $this->backups($tenant));
        $this->assertTrue($this->exists($kept[0]));
    }

    // --- Cảnh báo khi lỗi ------------------------------------------------------------------

    public function test_a_failed_automatic_backup_alerts_the_tenant_owner_and_staff_once_a_day(): void
    {
        [$tenant, $owner] = $this->makeTenant(['name' => 'Cửa hàng Nông Phát']);
        $staff = $this->makePlatformAdmin();
        $this->mock(TenantExporter::class, fn ($m) => $m->shouldReceive('export')->andThrow(new BackupException('Ổ đĩa đầy')));

        $this->artisan('agrihub:backup:tenants')->assertExitCode(0);

        $this->assertSame('failed', $this->backups($tenant, 'auto')->sole()->status);

        $mine = $this->platform(fn () => Notice::query()->where('user_id', $owner->id)->get())->sole();
        $this->assertSame('backup.tenant.auto_failed', $mine->type);
        $this->assertSame('critical', $mine->level);
        $this->assertStringContainsString('Ổ đĩa đầy', $mine->body);

        $theirs = $this->platform(fn () => Notice::query()->where('user_id', $staff->id)->get())->sole();
        $this->assertStringContainsString('Cửa hàng Nông Phát', $theirs->body);

        // Chạy lại cùng ngày => không báo trùng.
        $this->artisan('agrihub:backup:tenants')->assertExitCode(0);
        $this->assertCount(2, $this->backups($tenant, 'auto'));
        $this->assertCount(2, $this->platform(fn () => Notice::query()->get()));
    }

    // --- Khóa mã hóa -----------------------------------------------------------------------

    public function test_the_key_fingerprint_identifies_the_key_without_revealing_it(): void
    {
        config(['backup.encryption_key' => 'khoa-thu-nhat-'.str_repeat('a', 32)]);
        $crypto = app(BackupCrypto::class);
        $first = $crypto->keyFingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
        $this->assertSame($first, $crypto->keyFingerprint());
        $this->assertTrue($crypto->hasDedicatedKey());
        $this->assertStringNotContainsString(bin2hex($crypto->key()), $first);

        config(['backup.encryption_key' => 'khoa-thu-hai-'.str_repeat('b', 32)]);
        $this->assertNotSame($first, $crypto->keyFingerprint());

        config(['backup.encryption_key' => null]);
        $this->assertFalse($crypto->hasDedicatedKey());
    }

    public function test_exports_carry_the_fingerprint_and_the_command_reports_it(): void
    {
        [, $owner] = $this->makeTenant(['name' => 'Cửa hàng A'], ['password' => 'Mat-khau-cu-2026']);
        $uuid = $this->spa()->actingAs($owner)->postJson('/api/v1/backups')->assertStatus(202)->json('data.id');
        $fingerprint = app(BackupCrypto::class)->keyFingerprint();

        $this->spa()->actingAs($owner)->getJson("/api/v1/backups/{$uuid}")->assertJsonPath('data.key_fingerprint', $fingerprint);
        $this->spa()->actingAs($owner)->get("/api/v1/backups/{$uuid}/download")->assertOk()->assertHeader('X-Backup-Key-Fingerprint', $fingerprint);

        $this->artisan('agrihub:backup:key')->expectsOutputToContain($fingerprint)->assertExitCode(0);
    }

    public function test_generate_prints_a_fresh_random_key_of_the_right_size(): void
    {
        $this->artisan('agrihub:backup:key', ['--generate' => true])->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=base64:')->assertExitCode(0);

        // Hai lần tạo cho hai khóa khác nhau, mỗi khóa 32 byte.
        $keys = [];
        for ($i = 0; $i < 2; $i++) {
            Artisan::call('agrihub:backup:key', ['--generate' => true]);
            preg_match('/BACKUP_ENCRYPTION_KEY=base64:(\S+)/', Artisan::output(), $m);
            $keys[] = base64_decode($m[1], true);
        }

        $this->assertSame(32, strlen($keys[0]));
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_status_reports_the_encryption_and_warns_in_production_without_a_dedicated_key(): void
    {
        $admin = $this->makePlatformAdmin(['password' => 'Mat-khau-cu-2026']);
        config(['backup.platform.enabled' => true, 'backup.encryption_key' => null]);

        $status = $this->spa()->actingAs($admin)->getJson('/api/v1/platform/backups/status')->assertOk()
            ->assertJsonPath('data.encryption.dedicated_key', false)
            ->assertJsonPath('data.encryption.algorithm', 'XChaCha20-Poly1305 (libsodium secretstream)')
            ->json('data');
        $this->assertSame([], array_column($status['issues'], 'code'), 'ngoài production không cảnh báo');

        $this->app['env'] = 'production';
        $this->assertSame(['key_not_dedicated'], array_column(app(PlatformBackupMonitor::class)->status()['issues'], 'code'));
        $this->assertSame(1, app(PlatformBackupMonitor::class)->check()['notified']);
        $this->assertSame('backup.platform.key_not_dedicated', $this->platform(fn () => Notice::query()->where('user_id', $admin->id)->first())->type);

        config(['backup.encryption_key' => 'khoa-rieng-'.str_repeat('c', 32)]);
        $this->assertSame([], array_column(app(PlatformBackupMonitor::class)->status()['issues'], 'code'));
    }
}
