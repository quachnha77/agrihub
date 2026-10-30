<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupTenants extends Command
{
    protected $signature = 'agrihub:backup:tenants
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu BACKUP_TENANT_AUTO_ENABLED=false}';

    protected $description = 'Sao lưu tự động dữ liệu nghiệp vụ của TỪNG Tenant (loại "auto"), chạy nền qua queue worker';

    public function handle(BackupService $backups): int
    {
        if ($this->option('scheduled') && ! config('backup.tenant_auto.enabled')) {
            $this->line('Sao lưu tự động theo Tenant đang tắt (BACKUP_TENANT_AUTO_ENABLED=false); bỏ qua.');

            return self::SUCCESS;
        }

        $result = $backups->requestAutoForAll();

        $this->info("Đã yêu cầu sao lưu cho {$result['queued']} doanh nghiệp; bỏ qua {$result['skipped']} (đang khôi phục hoặc đang có bản chưa xong).");

        return self::SUCCESS;
    }
}
