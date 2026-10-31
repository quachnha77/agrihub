<?php

namespace Tests\Feature\Backup;

use App\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AdministersBackups;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsAuditLog;
use Tests\TestCase;

/** Tải bản sao lưu toàn hệ thống về nơi lưu riêng: nhập lại mật khẩu → liên kết ký, dùng một lần → file đã mã hóa. */
class PlatformBackupDownloadTest extends TestCase
{
    use AdministersBackups, CreatesTenants, ReadsAuditLog, RefreshDatabase;

    private const PASSWORD = 'Mat-khau-quan-tri-2026';

    private const CONTENT = "AGRIHUB-BACKUP\x01\nnoi-dung-da-ma-hoa";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(string $email = 'root@agrihub.test')
    {
        return $this->makePlatformAdmin(['email' => $email, 'password' => self::PASSWORD]);
    }

    private function backup(array $attributes = [], ?string $content = self::CONTENT): Backup
    {
        return $this->platform(function () use ($attributes, $content) {
            $backup = new Backup($attributes + [
                'scope' => Backup::SCOPE_PLATFORM, 'kind' => Backup::KIND_AUTO, 'status' => Backup::STATUS_COMPLETED,
                'disk' => 'backups', 'format_version' => 1, 'completed_at' => now(),
            ]);
            $backup->save();

            if ($content !== null) {
                $path = "platform/2026/10/{$backup->uuid}.agbk";
                Storage::disk('backups')->put($path, $content);
                $backup->forceFill(['path' => $path, 'size_bytes' => strlen($content), 'checksum_sha256' => hash('sha256', $content)])->save();
            }

            return $backup;
        });
    }

    private function reload(Backup $backup): Backup
    {
        return $this->platform(fn () => $backup->fresh());
    }

    private function link($admin, Backup $backup, string $password = self::PASSWORD): TestResponse
    {
        return $this->spa()->actingAs($admin)->postJson("/api/v1/platform/backups/{$backup->uuid}/download-link", ['password' => $password]);
    }

    public function test_download_needs_the_password_then_a_signed_one_time_link_and_returns_the_encrypted_file(): void
    {
        $admin = $this->admin();
        $backup = $this->backup();

        $response = $this->link($admin, $backup)->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
        $url = $response->json('data.url');

        // Liên kết tương đối (đi qua proxy/tên miền nào cũng khớp chữ ký), đã ký và gắn với đúng bản sao lưu.
        $this->assertStringStartsWith("/api/v1/platform/backups/{$backup->uuid}/download?", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('http', $url);
        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_download_requested', 'actor_id' => $admin->id]));
        $this->assertNull($this->reload($backup)->downloaded_at);

        $file = $this->spa()->actingAs($admin)->get($url)->assertOk();

        $this->assertSame(self::CONTENT, $file->streamedContent());
        $file->assertHeader('X-Content-SHA256', hash('sha256', self::CONTENT));
        $file->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $file->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/^attachment; filename=agrihub-platform-\d{8}-\d{6}\.agbk$/', (string) $file->headers->get('Content-Disposition'));

        $this->assertNotNull($this->reload($backup)->downloaded_at);
        $audit = $this->lastAudit(['module' => 'backup', 'action' => 'platform_downloaded']);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->tenant_id);
        $this->assertSame(hash('sha256', self::CONTENT), $audit->metadata['checksum_sha256']);
    }

    public function test_a_wrong_password_gets_no_link_and_is_audited(): void
    {
        $admin = $this->admin();
        $backup = $this->backup();

        $this->link($admin, $backup, 'sai-mat-khau')->assertStatus(422)
            ->assertJsonPath('code', 'confirmation_failed')->assertJsonPath('errors.password.0', 'Mật khẩu không đúng.');

        $this->assertSame(1, $this->auditCount(['module' => 'backup', 'action' => 'platform_download_denied', 'result' => 'denied']));
        $this->assertSame(0, $this->auditCount(['action' => 'platform_download_requested']));
        $this->spa()->actingAs($admin)->postJson("/api/v1/platform/backups/{$backup->uuid}/download-link", [])->assertStatus(422);
    }

    public function test_the_link_works_only_once(): void
    {
        $admin = $this->admin();
        $url = $this->link($admin, $this->backup())->json('data.url');

        $this->spa()->actingAs($admin)->get($url)->assertOk();
        $this->spa()->actingAs($admin)->getJson($url)->assertForbidden()->assertJsonPath('code', 'download_link_invalid');

        $this->assertSame(1, $this->auditCount(['action' => 'platform_downloaded']));
        $this->assertSame(1, $this->auditCount(['action' => 'platform_download_denied', 'result' => 'denied']));
    }

    public function test_the_link_expires_quickly(): void
    {
        $admin = $this->admin();
        $url = $this->link($admin, $this->backup())->json('data.url');

        Carbon::setTestNow(now()->addSeconds(121));

        $this->spa()->actingAs($admin)->getJson($url)->assertForbidden();
        $this->assertSame(0, $this->auditCount(['action' => 'platform_downloaded']));
    }

    public function test_a_tampered_or_guessed_link_is_refused(): void
    {
        $admin = $this->admin();
        $backup = $this->backup();
        $url = $this->link($admin, $backup)->json('data.url');

        // Sửa nonce => chữ ký sai.
        $tampered = preg_replace('/nonce=[^&]+/', 'nonce='.str_repeat('a', 40), $url);
        $this->spa()->actingAs($admin)->getJson($tampered)->assertForbidden();

        // Chữ ký hợp lệ nhưng nonce không do server cấp (không tồn tại) => vẫn bị từ chối.
        $forged = URL::temporarySignedRoute('platform.backups.download', now()->addMinute(), ['backup' => $backup->uuid, 'nonce' => str_repeat('b', 40)], absolute: false);
        $this->spa()->actingAs($admin)->getJson($forged)->assertForbidden()->assertJsonPath('code', 'download_link_invalid');

        // Liên kết gốc vẫn còn dùng được (các lần thử sai không "đốt" nó).
        $this->spa()->actingAs($admin)->get($url)->assertOk();
    }

    public function test_a_link_cannot_be_used_by_someone_else_or_for_another_backup(): void
    {
        $alice = $this->admin('alice@agrihub.test');
        $bob = $this->admin('bob@agrihub.test');
        $one = $this->backup();
        $two = $this->backup();

        $url = $this->link($alice, $one)->json('data.url');
        $this->freshBrowser()->spa()->actingAs($bob)->getJson($url)->assertForbidden()->assertJsonPath('code', 'download_link_invalid');

        // Liên kết của Alice đã bị "đốt" khi Bob thử — mỗi lần phải xin lại.
        $url = $this->freshBrowser()->link($alice, $one)->json('data.url');
        $swapped = str_replace($one->uuid, $two->uuid, $url);
        $this->spa()->actingAs($alice)->getJson($swapped)->assertForbidden(); // chữ ký không còn khớp
    }

    public function test_unfinished_or_missing_files_cannot_be_downloaded(): void
    {
        $admin = $this->admin();

        $failed = $this->backup(['status' => Backup::STATUS_FAILED, 'error' => 'x'], null);
        $this->link($admin, $failed)->assertStatus(409)->assertJsonPath('code', 'backup_not_ready');

        $lost = $this->backup();
        Storage::disk('backups')->delete($lost->path);
        $this->link($admin, $lost)->assertStatus(409)->assertJsonPath('code', 'backup_file_missing');
    }

    public function test_tenants_and_visitors_cannot_download_platform_backups(): void
    {
        [, $owner] = $this->makeTenant(['name' => 'Cửa hàng A'], ['password' => self::PASSWORD]);
        $admin = $this->admin();
        $backup = $this->backup();
        $url = $this->link($admin, $backup)->json('data.url');

        $this->freshBrowser()->spa()->actingAs($owner)->postJson("/api/v1/platform/backups/{$backup->uuid}/download-link", ['password' => self::PASSWORD])->assertForbidden();
        $this->spa()->actingAs($owner)->getJson($url)->assertForbidden();
        $this->spa()->actingAs($owner)->getJson("/api/v1/backups/{$backup->uuid}/download")->assertNotFound();

        $this->freshBrowser()->spa()->postJson("/api/v1/platform/backups/{$backup->uuid}/download-link", ['password' => self::PASSWORD])->assertUnauthorized();
        $this->spa()->getJson($url)->assertUnauthorized();

        $this->assertNull($this->reload($backup)->downloaded_at);
    }

    public function test_a_tenant_backup_is_not_reachable_through_the_platform_download_routes(): void
    {
        [$tenant] = $this->makeTenant(['name' => 'Cửa hàng B']);
        $admin = $this->admin();
        $tenantBackup = $this->platform(function () use ($tenant) {
            $b = new Backup(['scope' => Backup::SCOPE_TENANT, 'kind' => Backup::KIND_MANUAL, 'status' => Backup::STATUS_COMPLETED, 'disk' => 'backups', 'format_version' => 1]);
            $b->tenant_id = $tenant->id;
            $b->save();

            return $b;
        });

        $this->link($admin, $tenantBackup)->assertNotFound();
    }

    public function test_the_per_tenant_download_by_the_super_admin_also_records_the_download_time(): void
    {
        [$tenant] = $this->makeTenant(['name' => 'Cửa hàng C']);
        $uuid = $this->adminBackup($tenant)->uuid;

        $this->assertNull($this->asBackupAdmin()->getJson($this->backupPath($tenant, "/{$uuid}"))->json('data.downloaded_at'));
        $this->asBackupAdmin()->get($this->backupPath($tenant, "/{$uuid}/download"))->assertOk();
        $this->assertNotNull($this->asBackupAdmin()->getJson($this->backupPath($tenant, "/{$uuid}"))->json('data.downloaded_at'));
    }
}
