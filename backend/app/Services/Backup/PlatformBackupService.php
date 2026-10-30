<?php

namespace App\Services\Backup;

use App\Http\ApiProblemException;
use App\Jobs\Backup\RunPlatformBackupJob;
use App\Models\Backup;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sao lưu TOÀN HỆ THỐNG (mọi Tenant) cho khôi phục thảm họa: bản ghi + audit + vòng đời (sao lưu, kiểm thử khôi phục, dọn bản cũ).
 * Cơ chế pg_dump/pg_restore nằm ở PlatformDump. Bản ghi có tenant_id = NULL nên Tenant không bao giờ thấy được (RLS).
 */
class PlatformBackupService
{
    /** Liên kết tải về có hiệu lực ngắn và chỉ dùng được MỘT lần. */
    public const DOWNLOAD_LINK_SECONDS = 120;

    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
        private PlatformDump $dump,
        private PgTools $pg,
        private BackupAlerts $alerts,
        private BackupCrypto $crypto,
    ) {}

    // --- Sao lưu --------------------------------------------------------------------------

    public function hasRunning(): bool
    {
        return $this->context->runAsPlatform(fn () => Backup::query()
            ->where('scope', Backup::SCOPE_PLATFORM)
            ->whereIn('status', [Backup::STATUS_PENDING, Backup::STATUS_RUNNING])
            ->where('created_at', '>', now()->subHours((int) config('backup.platform.stale_hours')))
            ->exists());
    }

    /** Tạo bản ghi chờ chạy. Không đẩy vào hàng đợi — người gọi quyết định chạy ngay (lệnh) hay qua hàng đợi (API). */
    public function newRecord(?User $actor, string $kind): Backup
    {
        return $this->context->runAsPlatform(function () use ($actor, $kind) {
            $backup = new Backup([
                'scope' => Backup::SCOPE_PLATFORM,
                'kind' => $kind,
                'status' => Backup::STATUS_PENDING,
                'disk' => config('backup.disk'),
                'format_version' => (int) config('backup.format_version'),
                'created_by' => $actor?->id,
            ]);
            $backup->save();

            return $backup;
        });
    }

    /** Yêu cầu từ API: tạo bản ghi và đẩy vào hàng đợi. Chỉ một bản sao lưu toàn hệ thống chạy tại một thời điểm. */
    public function request(User $actor): Backup
    {
        if ($this->hasRunning()) {
            throw new ApiProblemException(409, 'backup_in_progress', 'Đang có một bản sao lưu toàn hệ thống chưa hoàn tất.');
        }

        $backup = $this->newRecord($actor, Backup::KIND_MANUAL);

        RunPlatformBackupJob::dispatch($backup->id);

        return $this->context->runAsPlatform(fn () => $backup->refresh());
    }

    /** Thực hiện sao lưu. Không ném lỗi: thất bại được ghi vào bản ghi (và audit). */
    public function run(Backup $backup): Backup
    {
        $actor = $this->actorOf($backup);
        $this->context->runAsPlatform(fn () => $backup->forceFill(['status' => Backup::STATUS_RUNNING, 'started_at' => now()])->save());

        $encrypted = $this->pg->tempPath('.agbk');

        try {
            $manifest = $this->dump->create($encrypted);

            $path = sprintf('platform/%s/%s.agbk', Carbon::now('UTC')->format('Y/m'), $backup->uuid);
            $handle = fopen($encrypted, 'rb');

            try {
                $this->disk()->put($path, $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            $this->context->runAsPlatform(fn () => $backup->forceFill([
                'status' => Backup::STATUS_COMPLETED,
                'path' => $path,
                'size_bytes' => filesize($encrypted),
                'checksum_sha256' => hash_file('sha256', $encrypted),
                'key_fingerprint' => $this->crypto->keyFingerprint(),
                'schema_version' => $manifest['schema_version'],
                'row_counts' => $manifest['row_counts'],
                'completed_at' => now(),
                'error' => null,
            ])->save());

            $this->record($backup, 'platform_created', $actor, metadata: [
                'kind' => $backup->kind, 'size_bytes' => $backup->size_bytes, 'tables' => count($manifest['row_counts']),
            ]);
        } catch (Throwable $e) {
            $this->fail($backup, $actor, $e);
        } finally {
            @unlink($encrypted);
        }

        return $this->context->runAsPlatform(fn () => $backup->refresh());
    }

    // --- Kiểm thử khôi phục ---------------------------------------------------------------

    /** Khôi phục thử vào database tạm, đối chiếu số dòng rồi xóa. Kết quả ghi vào bản ghi (verify_status). */
    public function verify(Backup $backup): Backup
    {
        $scratch = null;
        $error = null;

        try {
            $local = $this->intactLocalPath($backup);

            $scratch = config('backup.platform.verify_prefix').bin2hex(random_bytes(6));
            $this->dump->createDatabase($scratch);

            $manifest = $this->dump->restoreInto($local, $scratch);
            $problems = $this->dump->compare($manifest, $scratch);

            if ($problems !== []) {
                throw new BackupException('Khôi phục thử không khớp: '.implode('; ', array_slice($problems, 0, 5)));
            }
        } catch (Throwable $e) {
            $error = $e instanceof BackupException ? $e->getMessage() : 'Lỗi hệ thống khi kiểm thử khôi phục (xem nhật ký máy chủ).';

            if (! $e instanceof BackupException) {
                report($e);
            }
        } finally {
            if ($scratch !== null) {
                try {
                    $this->dump->dropDatabase($scratch);
                } catch (Throwable $e) {
                    report($e);
                    $error ??= "Kiểm thử đạt nhưng không xóa được database tạm {$scratch}; hãy xóa thủ công.";
                }
            }
        }

        $this->context->runAsPlatform(fn () => $backup->forceFill([
            'verify_status' => $error === null ? 'passed' : 'failed',
            'verified_at' => now(),
            'verify_error' => $error === null ? null : mb_substr($error, 0, 500),
        ])->save());

        $this->record($backup, $error === null ? 'platform_verified' : 'platform_verify_failed', null,
            result: $error === null ? AuditLogger::SUCCESS : AuditLogger::FAILURE, reason: $error === null ? null : mb_substr($error, 0, 255));

        $backup = $this->context->runAsPlatform(fn () => $backup->refresh());

        if ($error !== null) {
            $this->alerts->platformVerifyFailed($backup);
        }

        return $backup;
    }

    /** Bản sao lưu toàn hệ thống hoàn tất mới nhất. */
    public function latestCompleted(): ?Backup
    {
        return $this->context->runAsPlatform(fn () => Backup::query()
            ->where('scope', Backup::SCOPE_PLATFORM)->where('status', Backup::STATUS_COMPLETED)
            ->orderByDesc('created_at')->orderByDesc('id')->first());
    }

    public function findByUuid(string $uuid): ?Backup
    {
        return $this->context->runAsPlatform(fn () => Backup::query()->where('scope', Backup::SCOPE_PLATFORM)->where('uuid', $uuid)->first());
    }

    /** Đường dẫn cục bộ của file sau khi chắc chắn còn nguyên (đúng checksum đã ghi). */
    public function intactLocalPath(Backup $backup): string
    {
        if (! $backup->isCompleted() || $backup->path === null) {
            throw new BackupException('Bản sao lưu chưa hoàn tất hoặc đã lỗi.');
        }

        if (! $this->disk()->exists($backup->path)) {
            throw new BackupException('File sao lưu đã bị mất khỏi nơi lưu trữ.');
        }

        $local = $this->localPath($backup);

        if (hash_file('sha256', $local) !== $backup->checksum_sha256) {
            throw new BackupException('File sao lưu đã bị thay đổi (checksum không khớp).');
        }

        return $local;
    }

    // --- Khôi phục (dòng lệnh) ------------------------------------------------------------

    /**
     * Khôi phục vào database MỚI $target từ file (hoặc bản ghi). Ghi audit dù thành công hay thất bại.
     *
     * @return array<string, mixed> manifest
     */
    public function restoreToDatabase(string $file, string $target, ?string $owner, ?Backup $backup = null): array
    {
        try {
            $manifest = $this->dump->restoreIntoNewDatabase($file, $target, $owner);
        } catch (Throwable $e) {
            $this->auditRestore('platform_restore_failed', $backup, AuditLogger::FAILURE, mb_substr($e->getMessage(), 0, 255), ['target' => $target]);

            throw $e;
        }

        $this->auditRestore('platform_restored', $backup, AuditLogger::SUCCESS, null, [
            'target' => $target, 'owner' => $owner, 'tables' => count($manifest['row_counts']), 'created_at' => $manifest['created_at'] ?? null,
        ]);

        return $manifest;
    }

    // --- Dọn dẹp / giữ bản ----------------------------------------------------------------

    /**
     * Áp chính sách giữ bản cho các bản TỰ ĐỘNG và dọn bản bị gián đoạn. Bản thủ công không bao giờ bị xóa.
     *
     * @return array{kept: int, deleted: list<string>, failed_removed: list<string>, interrupted: int}
     */
    public function prune(bool $dryRun = false): array
    {
        $retention = (array) config('backup.platform.retention');
        $now = now();

        $interrupted = $dryRun ? 0 : $this->markInterrupted();

        // Chỉ bản tự động đã hoàn tất, mới nhất trước.
        $completed = $this->context->runAsPlatform(fn () => Backup::query()
            ->where('scope', Backup::SCOPE_PLATFORM)->where('kind', Backup::KIND_AUTO)->where('status', Backup::STATUS_COMPLETED)
            ->orderByDesc('created_at')->orderByDesc('id')->get());

        $dailyCut = $now->copy()->subDays((int) $retention['daily_days']);
        $weeklyCut = $now->copy()->subWeeks((int) $retention['weekly_weeks']);
        $monthlyCut = $now->copy()->subMonths((int) $retention['monthly_months']);
        $minKeep = (int) $retention['min_keep'];

        $weeks = [];
        $months = [];
        $kept = 0;
        $doomed = new Collection;

        foreach ($completed->values() as $index => $backup) {
            $at = $backup->created_at;

            if ($index < $minKeep || $at->greaterThanOrEqualTo($dailyCut)) {
                $kept++;
            } elseif ($at->greaterThanOrEqualTo($weeklyCut) && ! isset($weeks[$key = $at->format('o-W')])) {
                $weeks[$key] = true;
                $kept++;
            } elseif ($at->lessThan($weeklyCut) && $at->greaterThanOrEqualTo($monthlyCut) && ! isset($months[$key = $at->format('Y-m')])) {
                $months[$key] = true;
                $kept++;
            } else {
                $doomed->push($backup);
            }
        }

        // Bản lỗi quá hạn giữ (chỉ để tra cứu) cũng được dọn.
        $failed = $this->context->runAsPlatform(fn () => Backup::query()
            ->where('scope', Backup::SCOPE_PLATFORM)->where('status', Backup::STATUS_FAILED)->where('created_at', '<', $dailyCut)->get());

        $deleted = [];
        $failedRemoved = [];

        if (! $dryRun) {
            foreach ($doomed as $backup) {
                if ($this->remove($backup)) {
                    $deleted[] = $backup->uuid;
                }
            }

            foreach ($failed as $backup) {
                if ($this->remove($backup)) {
                    $failedRemoved[] = $backup->uuid;
                }
            }

            if ($deleted !== [] || $failedRemoved !== [] || $interrupted > 0) {
                $this->audit->record('backup', 'platform_pruned', actor: null, metadata: [
                    'deleted' => $deleted, 'failed_removed' => count($failedRemoved), 'interrupted' => $interrupted, 'kept' => $kept,
                ]);
            }
        } else {
            $deleted = $doomed->pluck('uuid')->all();
            $failedRemoved = $failed->pluck('uuid')->all();
        }

        return ['kept' => $kept, 'deleted' => $deleted, 'failed_removed' => $failedRemoved, 'interrupted' => $interrupted];
    }

    /** Bản "chờ/đang chạy" quá lâu là do worker bị tắt giữa chừng: đánh dấu lỗi để không chặn bản sao lưu kế tiếp. */
    public function markInterrupted(): int
    {
        $count = $this->context->runAsPlatform(fn () => Backup::query()
            ->where('scope', Backup::SCOPE_PLATFORM)
            ->whereIn('status', [Backup::STATUS_PENDING, Backup::STATUS_RUNNING])
            ->where('created_at', '<', now()->subHours((int) config('backup.platform.stale_hours')))
            ->update(['status' => Backup::STATUS_FAILED, 'error' => 'Bị gián đoạn: quá thời gian chờ mà chưa hoàn tất.', 'completed_at' => now()]));

        if ($count > 0) {
            $this->alerts->platformInterrupted($count);
        }

        return $count;
    }

    // --- Tải về nơi lưu riêng -------------------------------------------------------------

    /**
     * Bước 1: nhập lại mật khẩu của chính mình để nhận liên kết tải. Liên kết (tương đối, đã ký) hết hạn sau
     * DOWNLOAD_LINK_SECONDS giây, chỉ dùng được một lần và chỉ đúng người, đúng bản sao lưu đã xin.
     * Bản sao lưu chứa dữ liệu mọi khách hàng nên việc tải luôn được ghi audit.
     *
     * @return array{url: string, expires_at: string}
     */
    public function issueDownloadLink(User $actor, Backup $backup, string $password): array
    {
        $this->assertDownloadable($backup);

        if (! Hash::check($password, $actor->password)) {
            $this->record($backup, 'platform_download_denied', $actor, AuditLogger::DENIED, 'invalid_password');

            throw new ApiProblemException(422, 'confirmation_failed', 'Mật khẩu không đúng.', ['errors' => ['password' => ['Mật khẩu không đúng.']]]);
        }

        $nonce = Str::random(40);
        $expires = now()->addSeconds(self::DOWNLOAD_LINK_SECONDS);

        Cache::put($this->grantKey($nonce), ['user' => $actor->id, 'backup' => $backup->id], $expires);

        $this->record($backup, 'platform_download_requested', $actor);

        return [
            // Tương đối: đi qua proxy/tên miền nào cũng khớp chữ ký.
            'url' => URL::temporarySignedRoute('platform.backups.download', $expires, ['backup' => $backup->uuid, 'nonce' => $nonce], absolute: false),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /** Bước 2: trả file (đã mã hóa) theo luồng. Header X-Content-SHA256 để đối chiếu bản đã tải bằng `sha256sum`. */
    public function download(Backup $backup, User $actor, string $nonce): StreamedResponse
    {
        $grant = Cache::pull($this->grantKey($nonce)); // pull = đọc và xóa: chỉ dùng một lần

        if (! is_array($grant) || $grant['user'] !== $actor->id || $grant['backup'] !== $backup->id) {
            $this->record($backup, 'platform_download_denied', $actor, AuditLogger::DENIED, 'invalid_or_used_link');

            throw new ApiProblemException(403, 'download_link_invalid', 'Liên kết tải xuống không hợp lệ, đã hết hạn hoặc đã được dùng. Hãy yêu cầu liên kết mới.');
        }

        $this->assertDownloadable($backup);

        $this->context->runAsPlatform(fn () => $backup->forceFill(['downloaded_at' => now()])->save());
        $this->record($backup, 'platform_downloaded', $actor, metadata: ['size_bytes' => $backup->size_bytes, 'checksum_sha256' => $backup->checksum_sha256]);

        $name = sprintf('agrihub-platform-%s.agbk', ($backup->completed_at ?? $backup->created_at)->copy()->utc()->format('Ymd-His'));

        return $this->disk()->download($backup->path, $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => (string) $backup->checksum_sha256,
            'X-Backup-Key-Fingerprint' => (string) $backup->key_fingerprint,
        ]);
    }

    private function assertDownloadable(Backup $backup): void
    {
        if (! $backup->isCompleted() || $backup->path === null) {
            throw new ApiProblemException(409, 'backup_not_ready', 'Bản sao lưu chưa hoàn tất hoặc đã lỗi.');
        }

        if (! $this->disk()->exists($backup->path)) {
            throw new ApiProblemException(409, 'backup_file_missing', 'File sao lưu đã bị mất khỏi nơi lưu trữ.');
        }
    }

    private function grantKey(string $nonce): string
    {
        return 'platform-backup-download:'.hash('sha256', $nonce);
    }

    // --- nội bộ ---------------------------------------------------------------------------

    private function remove(Backup $backup): bool
    {
        try {
            if ($backup->path !== null) {
                $this->disk()->delete($backup->path); // idempotent: file đã mất thì coi như đã xóa
            }

            $this->context->runAsPlatform(fn () => $backup->delete());

            return true;
        } catch (Throwable $e) {
            report($e); // giữ bản ghi để lần dọn sau thử lại

            return false;
        }
    }

    private function fail(Backup $backup, ?User $actor, Throwable $e): void
    {
        $safe = $e instanceof BackupException;

        if (! $safe) {
            report($e);
        }

        $message = $safe ? mb_substr($e->getMessage(), 0, 500) : 'Lỗi hệ thống khi sao lưu (xem nhật ký máy chủ).';

        $this->context->runAsPlatform(fn () => $backup->forceFill([
            'status' => Backup::STATUS_FAILED, 'error' => $message, 'completed_at' => now(),
        ])->save());

        $this->record($backup, 'platform_failed', $actor, result: AuditLogger::FAILURE, reason: mb_substr($message, 0, 255));
        $this->alerts->platformBackupFailed($this->context->runAsPlatform(fn () => $backup->refresh()));
    }

    private function record(Backup $backup, string $action, ?User $actor, string $result = AuditLogger::SUCCESS, ?string $reason = null, array $metadata = []): void
    {
        $this->audit->record('backup', $action, subject: $backup, actor: $actor, result: $result, reason: $reason, metadata: $metadata + ['backup' => $backup->uuid]);
    }

    /** Khi thảm họa làm mất cả database ứng dụng thì không ghi được audit; việc khôi phục vẫn phải hoàn tất. */
    private function auditRestore(string $action, ?Backup $backup, string $result, ?string $reason, array $metadata): void
    {
        try {
            $this->audit->record('backup', $action, subject: $backup, actor: null, result: $result, reason: $reason, metadata: $metadata);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function actorOf(Backup $backup): ?User
    {
        return $backup->created_by === null ? null : $this->context->runAsPlatform(fn () => User::find($backup->created_by));
    }

    /** Đường dẫn cục bộ của file (disk local). Disk từ xa (S3) sẽ cần tải về thư mục tạm — chưa hỗ trợ. */
    private function localPath(Backup $backup): string
    {
        return $this->disk()->path($backup->path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('backup.disk'));
    }
}
