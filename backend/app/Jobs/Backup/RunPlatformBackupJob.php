<?php

namespace App\Jobs\Backup;

use App\Models\Backup;
use App\Services\Backup\PlatformBackupService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sao lưu toàn hệ thống trong nền. Không tự thử lại: kết quả (kể cả lỗi) nằm trong bản ghi backups. */
class RunPlatformBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public int $backupId) {}

    public function handle(PlatformBackupService $service, TenantContext $context): void
    {
        $backup = $context->runAsPlatform(fn () => Backup::withoutGlobalScopes()->find($this->backupId));

        if ($backup !== null && $backup->isPlatform() && $backup->status === Backup::STATUS_PENDING) {
            $service->run($backup);
        }
    }
}
