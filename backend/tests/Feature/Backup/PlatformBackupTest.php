<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\BackupException;
use App\Services\Backup\PgTools;
use App\Services\Backup\PlatformBackupService;
use App\Services\Backup\PlatformDump;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\Concerns\UsesPlatformDump;
use Tests\TestCase;

class PlatformBackupTest extends TestCase
{
    use CreatesTenants, ReadsAuditLog, RefreshDatabase, UsesPlatformDump;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformDump();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownPlatformDump();
        parent::tearDown();
    }

    private function service(): PlatformBackupService
    {
        return app(PlatformBackupService::class);
    }

    private function runBackup(string $kind = Backup::KIND_MANUAL): Backup
    {
        return $this->service()->run($this->service()->newRecord(null, $kind));
    }

    private function localPath(Backup $backup): string
    {
        return Storage::disk('backups')->path($backup->path);
    }

    /** Lật một bit trong file đã lưu (giả lập hỏng/sửa lén). */
    private function corrupt(Backup $backup, bool $updateChecksum): void
    {
        $bytes = Storage::disk('backups')->get($backup->path);
        $bytes[strlen($bytes) - 5] = chr(ord($bytes[strlen($bytes) - 5]) ^ 1);
        Storage::disk('backups')->put($backup->path, $bytes);

        if ($updateChecksum) {
            $this->platform(fn () => $backup->forceFill(['checksum_sha256' => hash('sha256', $bytes)])->save());
        }
    }

    private function tempFiles(): array
    {
        return glob(storage_path('app/backup-tmp').DIRECTORY_SEPARATOR.'*') ?: [];
    }

    private function tableOwner(string $db, string $table): string
    {
        return (string) app(PgTools::class)->pdo($db)->query("SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = '{$table}'")->fetchColumn();
    }

    // --- Sao lưu ---------------------------------------------------------------------------

    public function test_platform_backup_is_encrypted_checksummed_and_covers_every_tenants_rows(): void
    {
        $backup = $this->runBackup();

        $this->assertSame('completed', $backup->status, (string) $backup->error);
        $this->assertSame(Backup::SCOPE_PLATFORM, $backup->scope);
        $this->assertNull($backup->tenant_id);
        $this->assertStringStartsWith('platform/', $backup->path);

        // items có RLS FORCE và chứa dữ liệu của 2 Tenant: sao lưu phải lấy đủ cả hai.
        $counts = $backup->row_counts;
        ksort($counts);
        $this->assertSame(['events' => 3, 'items' => 3, 'migrations' => 2, 'notes' => 2], $counts);
        $this->assertArrayNotHasKey('sessions', $backup->row_counts);
        $this->assertSame('2026_02_01_000000_b', $backup->schema_version);

        $stored = Storage::disk('backups')->get($backup->path);
        $this->assertStringStartsWith(BackupCrypto::MAGIC, $stored);
        $this->assertSame(hash('sha256', $stored), $backup->checksum_sha256);
        $this->assertSame(strlen($stored), (int) $backup->size_bytes);
        foreach (['Phân NPK', 'AGRIHUB-PLATFORM', 'PGDMP', 'phiên đăng nhập cũ', 'items'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "bản rõ '{$needle}' lộ trong file đã mã hóa");
        }

        $audit = $this->lastAudit(['module' => 'backup', 'action' => 'platform_created']);
        $this->assertNotNull($audit);
        $this->assertNull($audit->tenant_id);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame([], $this->tempFiles(), 'file tạm phải được xóa');
    }

    public function test_a_failed_dump_never_leaves_a_usable_file(): void
    {
        config(['backup.platform.pg_bin' => storage_path('khong-co-thu-muc-nay')]);

        $backup = $this->runBackup();

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('BACKUP_PG_BIN', $backup->error);
        $this->assertNull($backup->path);
        $this->assertNull($backup->checksum_sha256);
        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertSame([], $this->tempFiles());
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_failed', 'result' => 'failure']));
    }

    public function test_the_application_role_is_refused_because_it_cannot_see_other_tenants(): void
    {
        config(['backup.platform.username' => config('database.connections.pgsql.username'), 'backup.platform.password' => config('database.connections.pgsql.password')]);

        $backup = $this->runBackup();

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('BYPASSRLS', $backup->error);
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_connection_failures_are_reported_without_leaking_the_password(): void
    {
        config(['backup.platform.database' => 'khong_ton_tai_xyz']);

        $backup = $this->runBackup();

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('Không kết nối được', $backup->error);
        $this->assertStringNotContainsString((string) config('backup.platform.password'), $backup->error);
    }

    // --- Kiểm thử khôi phục ----------------------------------------------------------------

    public function test_verify_restores_into_a_scratch_database_compares_counts_and_cleans_up(): void
    {
        $backup = $this->service()->verify($this->runBackup());

        $this->assertSame('passed', $backup->verify_status, (string) $backup->verify_error);
        $this->assertNotNull($backup->verified_at);
        $this->assertNull($backup->verify_error);
        $this->assertSame([], $this->leftoverScratchDatabases(), 'database tạm phải được xóa');
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_verified']));
        $this->assertSame([], $this->tempFiles());
    }

    public function test_verify_fails_when_the_stored_file_was_changed(): void
    {
        $backup = $this->runBackup();
        $this->corrupt($backup, updateChecksum: false);

        $backup = $this->service()->verify($backup);

        $this->assertSame('failed', $backup->verify_status);
        $this->assertStringContainsString('checksum', $backup->verify_error);
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_verify_failed', 'result' => 'failure']));
    }

    public function test_verify_fails_on_a_forged_file_even_with_a_matching_checksum_and_still_cleans_up(): void
    {
        $backup = $this->runBackup();
        $this->corrupt($backup, updateChecksum: true);

        $backup = $this->service()->verify($backup);

        $this->assertSame('failed', $backup->verify_status);
        $this->assertStringContainsString('không hợp lệ', $backup->verify_error);
        $this->assertSame([], $this->leftoverScratchDatabases());
    }

    public function test_verify_fails_when_the_file_is_missing(): void
    {
        $backup = $this->runBackup();
        Storage::disk('backups')->delete($backup->path);

        $this->assertSame('failed', $this->service()->verify($backup)->verify_status);
    }

    public function test_excluded_tables_keep_their_structure_but_not_their_data(): void
    {
        $backup = $this->runBackup();
        $target = $this->newDbName();

        $this->service()->restoreToDatabase($this->localPath($backup), $target, null, $backup);

        $pdo = app(PgTools::class)->pdo($target);
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM sessions')->fetchColumn(), 'phiên đăng nhập cũ không được sống lại');
        $this->assertSame(3, (int) $pdo->query('SELECT count(*) FROM items')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT count(*) FROM events')->fetchColumn());
        $this->assertSame('Phân NPK Ư', $pdo->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn());
    }

    public function test_compare_reports_every_kind_of_drift(): void
    {
        $backup = $this->runBackup();
        $target = $this->newDbName();
        $manifest = $this->service()->restoreToDatabase($this->localPath($backup), $target, null, $backup);
        $dump = app(PlatformDump::class);

        $this->assertSame([], $dump->compare($manifest, $target));

        $pdo = app(PgTools::class)->pdo($target);
        $pdo->exec('DELETE FROM notes WHERE id = 1');
        $pdo->exec("INSERT INTO sessions VALUES ('z', 'x')");
        $pdo->exec('CREATE TABLE them_vao (id int)');
        $pdo->exec('DROP TABLE migrations');

        $problems = implode(' | ', $dump->compare($manifest, $target));
        $this->assertStringContainsString('bảng notes: gốc 2 dòng, sau khôi phục 1', $problems);
        $this->assertStringContainsString('bảng sessions lẽ ra phải rỗng', $problems);
        $this->assertStringContainsString('them_vao không có trong bản sao lưu', $problems);
        $this->assertStringContainsString('thiếu bảng migrations', $problems);
    }

    // --- Khôi phục thảm họa ----------------------------------------------------------------

    public function test_restore_creates_a_new_database_owned_by_the_given_role_with_rls_intact(): void
    {
        $owner = (string) config('database.connections.pgsql.username');
        $backup = $this->runBackup();
        $target = $this->newDbName();

        $manifest = $this->service()->restoreToDatabase($this->localPath($backup), $target, $owner, $backup);

        $this->assertSame(3, $manifest['row_counts']['items']);
        $this->assertSame($owner, $this->tableOwner($target, 'items'));
        $this->assertSame($owner, $this->tableOwner($target, 'events_2026_10'));

        $pdo = app(PgTools::class)->pdo($target);
        $this->assertSame('true/true', $pdo->query("SELECT relrowsecurity::text || '/' || relforcerowsecurity::text FROM pg_class WHERE relname = 'items'")->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT count(*) FROM pg_policies WHERE tablename = 'items' AND policyname = 'tenant_isolation'")->fetchColumn());
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_restored']));
    }

    public function test_restore_never_targets_the_live_database_an_existing_one_or_a_bad_name(): void
    {
        $backup = $this->runBackup();
        $file = $this->localPath($backup);
        $existing = $this->newDbName();
        app(PlatformDump::class)->createDatabase($existing);

        $cases = [
            [config('database.connections.pgsql.database'), 'đang chạy'],
            [$this->srcDb, 'đang chạy'],
            [strtoupper($this->srcDb), 'không hợp lệ'],
            [$existing, 'đã tồn tại'],
            ['x; DROP DATABASE postgres', 'không hợp lệ'],
            ['ab', 'không hợp lệ'],
        ];

        foreach ($cases as [$target, $expected]) {
            try {
                $this->service()->restoreToDatabase($file, $target, null, $backup);
                $this->fail("Lẽ ra phải từ chối '{$target}'");
            } catch (BackupException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $target);
            }
        }

        $this->assertSame(count($cases), $this->auditCount(['module' => 'backup', 'action' => 'platform_restore_failed']));
        $this->assertSame(3, $this->sourceCount('items'), 'database nguồn không bị đụng tới');
    }

    public function test_a_tampered_file_leaves_no_half_restored_database_behind(): void
    {
        $backup = $this->runBackup();
        $this->corrupt($backup, updateChecksum: true);
        $target = $this->newDbName();

        try {
            $this->service()->restoreToDatabase($this->localPath($backup), $target, null, $backup);
            $this->fail('Lẽ ra phải từ chối file bị sửa');
        } catch (BackupException $e) {
            $this->assertStringContainsString('không hợp lệ', $e->getMessage());
        }

        $this->assertFalse(app(PlatformDump::class)->databaseExists($target));
        $this->assertSame([], $this->tempFiles());
    }

    public function test_the_restore_command_works_from_a_bare_file_when_the_backups_table_is_gone(): void
    {
        $backup = $this->runBackup();
        $file = $this->localPath($backup);
        $target = $this->newDbName();
        $this->platform(fn () => $backup->delete()); // thảm họa: không còn bản ghi nào trong database

        $this->artisan('agrihub:backup:restore-platform', ['--file' => $file, '--target' => $target, '--yes' => true, '--no-owner' => true])
            ->expectsOutputToContain('số dòng từng bảng khớp')
            ->assertExitCode(0);

        $this->assertTrue(app(PlatformDump::class)->databaseExists($target));
    }

    public function test_the_restore_command_refuses_without_a_target_or_for_the_live_database(): void
    {
        $this->runBackup();

        $this->artisan('agrihub:backup:restore-platform', ['--yes' => true])
            ->expectsOutputToContain('Cần --target')->assertExitCode(1);

        $this->artisan('agrihub:backup:restore-platform', ['--target' => $this->srcDb, '--yes' => true])
            ->expectsOutputToContain('đang chạy')->assertExitCode(1);

        $this->artisan('agrihub:backup:restore-platform', ['--target' => $this->newDbName(), '--file' => '/khong/co/file.agbk', '--yes' => true])
            ->expectsOutputToContain('Không thấy file')->assertExitCode(1);
    }

    public function test_the_restore_command_asks_for_confirmation_and_does_nothing_when_declined(): void
    {
        $this->runBackup();
        $target = $this->newDbName();

        $this->artisan('agrihub:backup:restore-platform', ['--target' => $target])
            ->expectsConfirmation("Khôi phục bản {$this->service()->latestCompleted()->uuid} ({$this->service()->latestCompleted()->created_at->format('Y-m-d H:i')}) vào database MỚI '{$target}'?", 'no')
            ->assertExitCode(1);

        $this->assertFalse(app(PlatformDump::class)->databaseExists($target));
    }

    // --- Lệnh và lịch ----------------------------------------------------------------------

    public function test_the_backup_command_creates_manual_and_scheduled_auto_backups(): void
    {
        $this->artisan('agrihub:backup:platform')->expectsOutputToContain('Đã sao lưu')->assertExitCode(0);
        $this->assertSame(Backup::KIND_MANUAL, $this->service()->latestCompleted()->kind);

        config(['backup.platform.enabled' => true]);
        $this->artisan('agrihub:backup:platform', ['--scheduled' => true])->assertExitCode(0);
        $this->assertSame(Backup::KIND_AUTO, $this->service()->latestCompleted()->kind);
    }

    public function test_scheduled_commands_do_nothing_while_automatic_backup_is_disabled(): void
    {
        config(['backup.platform.enabled' => false]);

        $this->artisan('agrihub:backup:platform', ['--scheduled' => true])->expectsOutputToContain('đang tắt')->assertExitCode(0);
        $this->artisan('agrihub:backup:prune', ['--scheduled' => true])->assertExitCode(0);
        $this->artisan('agrihub:backup:verify', ['--scheduled' => true])->assertExitCode(0);

        $this->assertNull($this->service()->latestCompleted());
        $this->assertSame(0, $this->platform(fn () => Backup::count()));
    }

    public function test_the_backup_command_does_not_run_twice_at_once(): void
    {
        $this->service()->newRecord(null, Backup::KIND_AUTO); // một bản đang chờ

        $this->artisan('agrihub:backup:platform')->expectsOutputToContain('chưa hoàn tất')->assertExitCode(1);
        $this->assertSame(1, $this->platform(fn () => Backup::count()));
    }

    public function test_the_backup_command_can_queue_instead_of_running_inline(): void
    {
        $this->artisan('agrihub:backup:platform', ['--queue' => true])->expectsOutputToContain('hàng đợi')->assertExitCode(0);

        // QUEUE_CONNECTION=sync trong test nên job đã chạy xong.
        $this->assertSame('completed', $this->platform(fn () => Backup::firstOrFail()->status));
    }

    public function test_the_verify_command_reports_success_and_failure_through_its_exit_code(): void
    {
        $this->artisan('agrihub:backup:verify')->expectsOutputToContain('Chưa có bản sao lưu')->assertExitCode(1);
        $this->artisan('agrihub:backup:verify', ['backup' => '00000000-0000-4000-8000-000000000000'])->expectsOutputToContain('Không tìm thấy')->assertExitCode(1);

        $backup = $this->runBackup();
        $this->artisan('agrihub:backup:verify')->expectsOutputToContain('ĐẠT')->assertExitCode(0);

        $this->corrupt($backup, updateChecksum: false);
        $this->artisan('agrihub:backup:verify', ['backup' => $backup->uuid])->expectsOutputToContain('KHÔNG ĐẠT')->assertExitCode(1);
    }

    public function test_the_schedule_registers_backup_prune_and_verify(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        foreach (['agrihub:backup:platform --scheduled', 'agrihub:backup:prune --scheduled', 'agrihub:backup:verify --scheduled'] as $command) {
            $this->assertStringContainsString($command, $output);
        }
    }

    // --- Giữ bản / dọn dẹp -----------------------------------------------------------------

    private function aged(int $days, string $kind = Backup::KIND_AUTO, string $status = Backup::STATUS_COMPLETED): Backup
    {
        return $this->platform(function () use ($days, $kind, $status) {
            $at = now()->subDays($days);
            $path = 'platform/test/'.bin2hex(random_bytes(6)).'.agbk';
            Storage::disk('backups')->put($path, 'x');

            $backup = new Backup(['scope' => 'platform', 'kind' => $kind, 'status' => $status, 'disk' => 'backups', 'path' => $path, 'format_version' => 1]);
            $backup->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $backup;
        });
    }

    public function test_retention_keeps_daily_then_weekly_then_monthly_and_never_touches_manual_backups(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00'); // thứ Tư
        config(['backup.platform.retention' => ['daily_days' => 30, 'weekly_weeks' => 12, 'monthly_months' => 12, 'min_keep' => 3]]);

        $keep = collect([1, 10, 29, 35, 42, 100, 200])->map(fn ($d) => $this->aged($d)); // 35 là bản mới nhất của tuần đó; 100 của tháng 7
        $drop = collect([36, 105, 400])->map(fn ($d) => $this->aged($d));              // cùng tuần với 35; cùng tháng với 100; quá 12 tháng
        $manual = $this->aged(500, Backup::KIND_MANUAL);
        $failedOld = $this->aged(40, Backup::KIND_AUTO, Backup::STATUS_FAILED);
        $failedNew = $this->aged(5, Backup::KIND_AUTO, Backup::STATUS_FAILED);

        $dry = $this->service()->prune(dryRun: true);
        $this->assertEqualsCanonicalizing($drop->pluck('uuid')->all(), $dry['deleted']);
        $this->assertSame(13, $this->platform(fn () => Backup::count()), 'chạy thử không xóa gì');

        $result = $this->service()->prune();

        $this->assertEqualsCanonicalizing($drop->pluck('uuid')->all(), $result['deleted']);
        $this->assertSame([$failedOld->uuid], $result['failed_removed']);
        $this->assertSame(7, $result['kept']);

        foreach ($keep->concat([$manual, $failedNew]) as $backup) {
            $this->assertTrue(Storage::disk('backups')->exists($backup->path), "bản {$backup->created_at} phải còn");
            $this->assertNotNull($this->platform(fn () => Backup::find($backup->id)));
        }
        foreach ($drop->concat([$failedOld]) as $backup) {
            $this->assertFalse(Storage::disk('backups')->exists($backup->path));
            $this->assertNull($this->platform(fn () => Backup::find($backup->id)));
        }

        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_pruned']));
    }

    public function test_retention_never_deletes_the_newest_backups_even_if_the_schedule_died(): void
    {
        config(['backup.platform.retention' => ['daily_days' => 30, 'weekly_weeks' => 12, 'monthly_months' => 12, 'min_keep' => 3]]);
        $old = collect([500, 600, 700])->map(fn ($d) => $this->aged($d));

        $this->assertSame([], $this->service()->prune()['deleted']);
        $this->assertSame(3, $this->platform(fn () => Backup::count()));

        $this->aged(800);
        $this->assertCount(1, $this->service()->prune()['deleted'], 'chỉ bản cũ thứ 4 trở đi mới bị xóa');
        $this->assertNotNull($this->platform(fn () => Backup::find($old[0]->id)));
    }

    public function test_interrupted_backups_are_marked_failed_so_they_do_not_block_the_next_one(): void
    {
        $stale = $this->platform(function () {
            $b = new Backup(['scope' => 'platform', 'kind' => 'auto', 'status' => 'running', 'disk' => 'backups', 'format_version' => 1]);
            $b->forceFill(['created_at' => now()->subHours(13)])->save();

            return $b;
        });
        $recent = $this->service()->newRecord(null, Backup::KIND_AUTO);

        $this->assertSame(1, $this->service()->markInterrupted());

        $this->assertSame('failed', $this->platform(fn () => $stale->fresh()->status));
        $this->assertSame('pending', $this->platform(fn () => $recent->fresh()->status));
        $this->assertTrue($this->service()->hasRunning());
    }

    public function test_the_prune_command_reports_what_it_removes(): void
    {
        $this->aged(1);
        $this->aged(2);
        $this->aged(3);
        $this->aged(500);

        $this->artisan('agrihub:backup:prune', ['--dry-run' => true])->expectsOutputToContain('sẽ xóa 1 bản')->assertExitCode(0);
        $this->artisan('agrihub:backup:prune')->expectsOutputToContain('đã xóa 1 bản')->assertExitCode(0);
    }

    // --- API và cô lập ---------------------------------------------------------------------

    public function test_platform_admin_requests_lists_and_views_platform_backups(): void
    {
        $admin = $this->makePlatformAdmin();

        $res = $this->spa()->actingAs($admin)->postJson('/api/v1/platform/backups')
            ->assertStatus(202)
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.kind', 'manual')
            ->assertJsonPath('data.status', 'completed') // QUEUE_CONNECTION=sync nên job đã chạy xong
            ->assertJsonPath('data.row_counts.items', 3);
        $uuid = $res->json('data.id');

        $this->spa()->actingAs($admin)->getJson('/api/v1/platform/backups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $uuid);
        $this->spa()->actingAs($admin)->getJson("/api/v1/platform/backups/{$uuid}")->assertOk()->assertJsonPath('data.verify_status', null);
        $this->spa()->actingAs($admin)->getJson("/api/v1/platform/backups/{$uuid}/download")->assertNotFound();
        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/backups/{$uuid}/restore")->assertStatus(404);
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_created', 'actor_id' => $admin->id]));
    }

    public function test_only_one_platform_backup_runs_at_a_time(): void
    {
        $admin = $this->makePlatformAdmin();
        $this->service()->newRecord(null, Backup::KIND_AUTO);

        $this->spa()->actingAs($admin)->postJson('/api/v1/platform/backups')->assertStatus(409)->assertJsonPath('code', 'backup_in_progress');
    }

    public function test_a_tenant_can_neither_see_nor_reach_platform_backups(): void
    {
        [$tenant, $owner] = $this->makeTenant();
        $backup = $this->runBackup();
        $admin = $this->makePlatformAdmin();

        $this->spa()->actingAs($owner)->getJson('/api/v1/backups')->assertOk()->assertJsonCount(0, 'data');
        $this->spa()->actingAs($owner)->getJson("/api/v1/backups/{$backup->uuid}")->assertNotFound();
        $this->spa()->actingAs($owner)->getJson("/api/v1/backups/{$backup->uuid}/download")->assertNotFound();
        $this->spa()->actingAs($owner)->getJson('/api/v1/platform/backups')->assertForbidden();
        $this->spa()->actingAs($owner)->postJson('/api/v1/platform/backups')->assertForbidden();

        // RLS ở mức database: trong ngữ cảnh Tenant, bản ghi toàn hệ thống không tồn tại.
        $this->assertSame(0, app(TenantContext::class)->runForTenant($tenant->id, fn () => Backup::withoutGlobalScopes()->count()));

        // Super Admin cũng không lấy được qua đường dẫn của một Tenant.
        $this->spa()->actingAs($admin)->getJson("/api/v1/platform/tenants/{$tenant->uuid}/backups/{$backup->uuid}")->assertNotFound();
        $this->spa()->actingAs($admin)->getJson("/api/v1/platform/tenants/{$tenant->uuid}/backups/{$backup->uuid}/download")->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->spa()->getJson('/api/v1/platform/backups')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/platform/backups')->assertUnauthorized();
    }

    public function test_the_database_enforces_that_platform_backups_have_no_tenant_and_tenant_backups_have_one(): void
    {
        [$tenant] = $this->makeTenant();

        foreach ([['platform', $tenant->id], ['tenant', null]] as [$scope, $tenantId]) {
            try {
                // Savepoint: lỗi ràng buộc làm hỏng transaction của test nếu không bọc riêng.
                $this->platform(fn () => DB::transaction(fn () => DB::table('backups')->insert([
                    'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'scope' => $scope, 'kind' => 'manual',
                    'status' => 'pending', 'disk' => 'backups', 'format_version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ])));
                $this->fail("Lẽ ra database phải từ chối scope={$scope}, tenant_id=".var_export($tenantId, true));
            } catch (QueryException $e) {
                $this->assertStringContainsString('backups_scope_tenant_check', $e->getMessage());
            }
        }
    }
}
