<?php

namespace App\Jobs\Backup;

use App\Models\Backup;
use App\Services\Backup\BackupService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sao lưu một Tenant trong nền. Không tự thử lại: kết quả (kể cả lỗi) nằm trong bản ghi backups. */
class RunTenantBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $backupId) {}

    public function handle(BackupService $service, TenantContext $context): void
    {
        $backup = $context->runAsPlatform(fn () => Backup::withoutGlobalScopes()->find($this->backupId));

        if ($backup !== null && $backup->status === Backup::STATUS_PENDING) {
            $service->run($backup);
        }
    }
}
