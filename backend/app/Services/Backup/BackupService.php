<?php

namespace App\Services\Backup;

use App\Http\ApiProblemException;
use App\Jobs\Backup\RunTenantBackupJob;
use App\Jobs\Backup\RunTenantRestoreJob;
use App\Models\Backup;
use App\Models\RestoreRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sao lưu / khôi phục dữ liệu nghiệp vụ theo Tenant (quy tắc an toàn: docs/BACKUP_DISASTER_RECOVERY.md mục 6).
 *
 * Luồng: yêu cầu (HTTP) → job (nền) thực hiện. File luôn được mã hóa + xác thực trước khi nằm trên disk, kiểm tra checksum
 * trước khi khôi phục, và mỗi lần khôi phục đều tự tạo một bản `pre_restore` làm điểm quay lại.
 */
class BackupService
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
        private BackupCrypto $crypto,
        private TenantExporter $exporter,
        private TenantRestorer $restorer,
    ) {}

    // --- Sao lưu --------------------------------------------------------------------------

    /** Tạo yêu cầu sao lưu và đẩy vào hàng đợi. */
    public function request(Tenant $tenant, ?User $actor, string $kind = Backup::KIND_MANUAL): Backup
    {
        $backup = $this->newRecord($tenant, $actor, $kind);

        RunTenantBackupJob::dispatch($backup->id);

        return $this->context->runAsPlatform(fn () => $backup->refresh());
    }

    /** Thực hiện sao lưu (gọi từ job). Không ném lỗi: thất bại được ghi vào bản ghi. */
    public function run(Backup $backup): Backup
    {
        $tenant = $this->tenantOf($backup);

        return $this->context->runForTenant($tenant->id, function () use ($backup, $tenant) {
            $backup->forceFill(['status' => Backup::STATUS_RUNNING, 'started_at' => now()])->save();
            $plain = $this->temp('.ndjson.gz');
            $encrypted = $this->temp('.agbk');

            try {
                $counts = $this->exporter->export($tenant, $plain);
                $this->crypto->encryptFile($plain, $encrypted);

                $path = $this->pathFor($tenant, $backup);
                $this->disk()->put($path, fopen($encrypted, 'rb'));

                $backup->forceFill([
                    'status' => Backup::STATUS_COMPLETED,
                    'path' => $path,
                    'size_bytes' => filesize($encrypted),
                    'checksum_sha256' => hash_file('sha256', $encrypted),
                    'schema_version' => TenantExporter::schemaVersion(),
                    'row_counts' => $counts,
                    'completed_at' => now(),
                    'error' => null,
                ])->save();

                $this->record($backup, 'created', $this->actorOf($backup), metadata: ['kind' => $backup->kind, 'size_bytes' => $backup->size_bytes]);
            } catch (Throwable $e) {
                $this->fail($backup, $e);
            } finally {
                @unlink($plain);
                @unlink($encrypted);
            }

            return $backup->refresh();
        });
    }

    /** Import: nhận file tải lên, kiểm tra ngay (giải mã + cấu trúc + đúng Tenant) rồi lưu như một bản sao lưu. */
    public function storeUpload(Tenant $tenant, User $actor, UploadedFile $file): Backup
    {
        $backup = $this->newRecord($tenant, $actor, Backup::KIND_UPLOAD);
        $this->context->runAsPlatform(fn () => $backup->forceFill(['original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'started_at' => now()])->save());

        $uploaded = $file->getRealPath();
        $plain = $this->temp('.ndjson.gz');

        try {
            $this->context->runForTenant($tenant->id, function () use ($tenant, $backup, $uploaded, $plain) {
                $meta = $this->decryptAndInspect($uploaded, $plain, $tenant);

                $path = $this->pathFor($tenant, $backup);
                $this->disk()->put($path, fopen($uploaded, 'rb'));

                $backup->forceFill([
                    'status' => Backup::STATUS_COMPLETED,
                    'path' => $path,
                    'size_bytes' => filesize($uploaded),
                    'checksum_sha256' => hash_file('sha256', $uploaded),
                    'schema_version' => $meta['schema'],
                    'row_counts' => $meta['row_counts'],
                    'completed_at' => now(),
                ])->save();
            });

            $this->record($backup, 'uploaded', $actor, metadata: ['size_bytes' => $backup->size_bytes, 'name' => $backup->original_name]);
        } catch (BackupException $e) {
            $this->fail($backup, $e);
            $this->record($backup, 'upload_rejected', $actor, result: AuditLogger::FAILURE, reason: $e->getMessage());

            throw new ApiProblemException(422, 'backup_invalid', $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($backup, $e);

            throw $e;
        } finally {
            @unlink($plain);
        }

        return $this->context->runForTenant($tenant->id, fn () => $backup->refresh());
    }

    public function download(Backup $backup, User $actor): StreamedResponse
    {
        $this->assertUsable($backup);

        $this->record($backup, 'downloaded', $actor);

        $tenant = $this->tenantOf($backup);
        $name = sprintf('agrihub-backup-%s-%s.agbk', $tenant->slug, ($backup->completed_at ?? $backup->created_at)->utc()->format('Ymd-His'));

        return $this->disk()->download($backup->path, $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // --- Khôi phục ------------------------------------------------------------------------

    /** Kiểm tra điều kiện rồi tạo yêu cầu khôi phục và đẩy vào hàng đợi. */
    public function requestRestore(Tenant $tenant, User $actor, Backup $backup): RestoreRun
    {
        $this->assertUsable($backup);

        $busy = $this->context->runAsPlatform(fn () => RestoreRun::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->whereIn('status', [RestoreRun::STATUS_PENDING, RestoreRun::STATUS_RUNNING])->exists());

        if ($busy) {
            throw new ApiProblemException(409, 'restore_in_progress', 'Đang có một lần khôi phục khác chưa hoàn tất.');
        }

        $run = $this->context->runAsPlatform(function () use ($tenant, $actor, $backup) {
            $run = new RestoreRun(['backup_id' => $backup->id, 'status' => RestoreRun::STATUS_PENDING, 'performed_by' => $actor->id]);
            $run->tenant_id = $tenant->id;
            $run->save();

            return $run;
        });

        $this->record($backup, 'restore_requested', $actor, metadata: ['restore_run' => $run->uuid]);

        RunTenantRestoreJob::dispatch($run->id);

        return $this->context->runAsPlatform(fn () => $run->refresh());
    }

    /** Thực hiện khôi phục (gọi từ job). Không ném lỗi: kết quả nằm trong restore_runs. */
    public function runRestore(RestoreRun $run): RestoreRun
    {
        $tenant = $this->context->runAsPlatform(fn () => Tenant::findOrFail($run->tenant_id));
        $actor = $this->context->runAsPlatform(fn () => $run->performed_by ? User::find($run->performed_by) : null);
        $plain = $this->temp('.ndjson.gz');

        $this->context->runAsPlatform(fn () => $run->forceFill(['status' => RestoreRun::STATUS_RUNNING, 'started_at' => now()])->save());
        $locked = false;

        try {
            $backup = $this->context->runForTenant($tenant->id, fn () => Backup::findOrFail($run->backup_id));
            $this->assertUsable($backup);
            $this->assertIntact($backup);

            // Bảo trì: Tenant tạm ngừng nhận request trong lúc khôi phục.
            $this->setRestoring($tenant, true);
            $locked = true;

            // Điểm quay lại: sao lưu hiện trạng trước khi ghi đè. Không tạo được thì dừng, không khôi phục.
            $pre = $this->context->runForTenant($tenant->id, fn () => $this->run($this->newRecord($tenant, $actor, Backup::KIND_PRE_RESTORE)));
            if (! $pre->isCompleted()) {
                throw new BackupException('Không tạo được bản sao lưu hiện trạng (điểm quay lại) nên chưa khôi phục.');
            }
            $this->context->runAsPlatform(fn () => $run->forceFill(['pre_backup_id' => $pre->id])->save());

            $counts = $this->context->runForTenant($tenant->id, function () use ($backup, $tenant, $plain) {
                $this->crypto->decryptFile($this->localPath($backup), $plain, $this->maxBytes());

                return $this->restorer->restore($plain, $tenant);
            });

            $this->context->runAsPlatform(fn () => $run->forceFill([
                'status' => RestoreRun::STATUS_COMPLETED, 'row_counts' => $counts, 'finished_at' => now(), 'error' => null,
            ])->save());

            $this->record($backup, 'restored', $actor, metadata: ['restore_run' => $run->uuid, 'pre_restore_backup' => $pre->uuid, 'row_counts' => $counts]);
        } catch (Throwable $e) {
            $message = $e instanceof BackupException || $e instanceof ApiProblemException ? $e->getMessage() : 'Lỗi hệ thống khi khôi phục; dữ liệu hiện tại được giữ nguyên.';
            if (! ($e instanceof BackupException || $e instanceof ApiProblemException)) {
                report($e);
            }

            $this->context->runAsPlatform(fn () => $run->forceFill([
                'status' => RestoreRun::STATUS_FAILED, 'error' => mb_substr($message, 0, 500), 'finished_at' => now(),
            ])->save());

            $this->audit->record('backup', 'restore_failed', tenantId: $tenant->id, actor: $actor, result: AuditLogger::FAILURE,
                reason: mb_substr($message, 0, 255), metadata: ['restore_run' => $run->uuid, 'backup_id' => $run->backup_id]);
        } finally {
            if ($locked) {
                $this->setRestoring($tenant, false);
            }
            @unlink($plain);
        }

        return $this->context->runAsPlatform(fn () => $run->refresh());
    }

    // --- Trợ giúp công khai ---------------------------------------------------------------

    /** Xác nhận 2 bước trước khi khôi phục: nhập lại mật khẩu của chính mình + gõ đúng tên doanh nghiệp. */
    public function assertConfirmed(User $actor, Tenant $tenant, string $password, string $confirmName): void
    {
        $passwordOk = Hash::check($password, $actor->password);
        $nameOk = hash_equals($tenant->name, $confirmName);

        if (! $passwordOk || ! $nameOk) {
            $this->audit->record('backup', 'restore_confirmation_failed', tenantId: $tenant->id, actor: $actor, result: AuditLogger::DENIED,
                reason: ! $passwordOk ? 'invalid_password' : 'tenant_name_mismatch');

            throw new ApiProblemException(422, 'confirmation_failed', ! $passwordOk ? 'Mật khẩu không đúng.' : 'Tên doanh nghiệp xác nhận không khớp.', [
                'errors' => [! $passwordOk ? 'password' : 'confirm_name' => [! $passwordOk ? 'Mật khẩu không đúng.' : 'Tên doanh nghiệp xác nhận không khớp.']],
            ]);
        }
    }

    public function isRestoring(Tenant $tenant): bool
    {
        return $tenant->restoring_at !== null
            && $tenant->restoring_at->greaterThan(now()->subMinutes((int) config('backup.restore_lock_minutes')));
    }

    public function newRecord(Tenant $tenant, ?User $actor, string $kind): Backup
    {
        return $this->context->runAsPlatform(function () use ($tenant, $actor, $kind) {
            $backup = new Backup([
                'scope' => Backup::SCOPE_TENANT,
                'kind' => $kind,
                'status' => Backup::STATUS_PENDING,
                'disk' => config('backup.disk'),
                'format_version' => (int) config('backup.format_version'),
                'created_by' => $actor?->id,
            ]);
            $backup->tenant_id = $tenant->id;
            $backup->save();

            return $backup;
        });
    }

    // --- nội bộ ---------------------------------------------------------------------------

    /** @return array{schema: string, created_at: string, tables: list<string>, row_counts: array<string, int>} */
    private function decryptAndInspect(string $encryptedPath, string $plainPath, Tenant $tenant): array
    {
        $this->crypto->decryptFile($encryptedPath, $plainPath, $this->maxBytes());

        return $this->restorer->inspect($plainPath, $tenant);
    }

    private function assertUsable(Backup $backup): void
    {
        if (! $backup->isCompleted() || $backup->path === null) {
            throw new ApiProblemException(409, 'backup_not_ready', 'Bản sao lưu chưa hoàn tất hoặc đã lỗi.');
        }
    }

    /** File trên disk phải còn nguyên (checksum khớp) trước khi dùng để khôi phục. */
    private function assertIntact(Backup $backup): void
    {
        if (! $this->disk()->exists($backup->path) || hash_file('sha256', $this->localPath($backup)) !== $backup->checksum_sha256) {
            throw new BackupException('File sao lưu bị mất hoặc đã bị thay đổi (checksum không khớp).');
        }
    }

    /** Đường dẫn cục bộ của file sao lưu (disk local). Disk từ xa (S3) sẽ cần tải về thư mục tạm — chưa hỗ trợ. */
    private function localPath(Backup $backup): string
    {
        return $this->disk()->path($backup->path);
    }

    private function setRestoring(Tenant $tenant, bool $on): void
    {
        $this->context->runAsPlatform(fn () => DB::table('tenants')->where('id', $tenant->id)->update(['restoring_at' => $on ? now() : null]));
    }

    private function fail(Backup $backup, Throwable $e): void
    {
        $safe = $e instanceof BackupException;
        if (! $safe) {
            report($e);
        }

        $this->context->runAsPlatform(fn () => $backup->forceFill([
            'status' => Backup::STATUS_FAILED,
            'error' => $safe ? mb_substr($e->getMessage(), 0, 500) : 'Lỗi hệ thống khi sao lưu (xem nhật ký máy chủ).',
            'completed_at' => now(),
        ])->save());

        if ($backup->kind !== Backup::KIND_UPLOAD) {
            $this->record($backup, 'failed', $this->actorOf($backup), result: AuditLogger::FAILURE, reason: $backup->error);
        }
    }

    private function record(Backup $backup, string $action, ?User $actor, string $result = AuditLogger::SUCCESS, ?string $reason = null, array $metadata = []): void
    {
        $this->audit->record('backup', $action, subject: $backup, actor: $actor, result: $result, reason: $reason, metadata: $metadata + ['backup' => $backup->uuid]);
    }

    private function tenantOf(Backup $backup): Tenant
    {
        return $this->context->runAsPlatform(fn () => Tenant::findOrFail($backup->tenant_id));
    }

    private function actorOf(Backup $backup): ?User
    {
        return $backup->created_by === null ? null : $this->context->runAsPlatform(fn () => User::find($backup->created_by));
    }

    private function pathFor(Tenant $tenant, Backup $backup): string
    {
        return sprintf('tenants/%s/%s/%s.agbk', $tenant->uuid, Carbon::now('UTC')->format('Y/m'), $backup->uuid);
    }

    private function maxBytes(): int
    {
        return (int) config('backup.max_uncompressed_mb') * 1024 * 1024;
    }

    private function temp(string $suffix): string
    {
        $dir = storage_path('app/backup-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.DIRECTORY_SEPARATOR.Str::uuid().$suffix;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('backup.disk'));
    }
}
