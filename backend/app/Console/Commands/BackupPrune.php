<?php

namespace App\Console\Commands;

use App\Services\Backup\PlatformBackupService;
use Illuminate\Console\Command;

class BackupPrune extends Command
{
    protected $signature = 'agrihub:backup:prune
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu BACKUP_PLATFORM_ENABLED=false}
        {--dry-run : Chỉ liệt kê, không xóa gì}';

    protected $description = 'Dọn bản sao lưu toàn hệ thống tự động theo chính sách giữ bản (ngày/tuần/tháng); bản thủ công không bao giờ bị xóa';

    public function handle(PlatformBackupService $service): int
    {
        if ($this->option('scheduled') && ! config('backup.platform.enabled')) {
            $this->line('Sao lưu toàn hệ thống tự động đang tắt; bỏ qua.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $result = $service->prune($dry);

        $this->info(sprintf(
            '%sGiữ %d bản; %s %d bản quá hạn; %s %d bản lỗi cũ; đánh dấu gián đoạn %d bản.',
            $dry ? '[chạy thử] ' : '', $result['kept'], $dry ? 'sẽ xóa' : 'đã xóa', count($result['deleted']),
            $dry ? 'sẽ dọn' : 'đã dọn', count($result['failed_removed']), $result['interrupted'],
        ));

        foreach ($result['deleted'] as $uuid) {
            $this->line("  - {$uuid}");
        }

        return self::SUCCESS;
    }
}
