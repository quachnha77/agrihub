<?php

namespace App\Jobs\Backup;

use App\Models\RestoreRun;
use App\Services\Backup\BackupService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Khôi phục một Tenant trong nền. Không tự thử lại (khôi phục không được chạy hai lần ngoài ý muốn). */
class RunTenantRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public int $restoreRunId) {}

    public function handle(BackupService $service, TenantContext $context): void
    {
        $run = $context->runAsPlatform(fn () => RestoreRun::withoutGlobalScopes()->find($this->restoreRunId));

        if ($run !== null && $run->status === RestoreRun::STATUS_PENDING) {
            $service->runRestore($run);
        }
    }
}
