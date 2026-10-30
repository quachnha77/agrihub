<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Backup\PlatformBackupService;
use Illuminate\Console\Command;

class BackupPrune extends Command
{
    protected $signature = 'agrihub:backup:prune
        {--scheduled : Do bộ lập lịch gọi: mỗi phần chỉ chạy khi tính năng tương ứng đang bật}
        {--dry-run : Chỉ liệt kê, không xóa gì}';

    protected $description = 'Dọn bản sao lưu tự động cũ: toàn hệ thống (ngày/tuần/tháng) và từng Tenant; bản thủ công không bao giờ bị xóa';

    public function handle(PlatformBackupService $platform, BackupService $tenants): int
    {
        $dry = (bool) $this->option('dry-run');
        $scheduled = (bool) $this->option('scheduled');
        $prefix = $dry ? '[chạy thử] ' : '';

        if (! $scheduled || config('backup.platform.enabled')) {
            $result = $platform->prune($dry);

            $this->info(sprintf(
                '%sToàn hệ thống: giữ %d bản; %s %d bản quá hạn; %s %d bản lỗi cũ; đánh dấu gián đoạn %d bản.',
                $prefix, $result['kept'], $dry ? 'sẽ xóa' : 'đã xóa', count($result['deleted']),
                $dry ? 'sẽ dọn' : 'đã dọn', count($result['failed_removed']), $result['interrupted'],
            ));

            foreach ($result['deleted'] as $uuid) {
                $this->line("  - {$uuid}");
            }
        } else {
            $this->line('Sao lưu toàn hệ thống tự động đang tắt; bỏ qua phần toàn hệ thống.');
        }

        if (! $scheduled || config('backup.tenant_auto.enabled')) {
            $result = $tenants->pruneAuto($dry);

            $this->info(sprintf(
                '%sTheo Tenant: giữ %d bản tự động; %s %d bản quá hạn; giữ lại %d bản vì đang là nguồn/điểm quay lại của một lần khôi phục.',
                $prefix, $result['kept'], $dry ? 'sẽ xóa' : 'đã xóa', $result['deleted'], $result['protected'],
            ));
        } else {
            $this->line('Sao lưu tự động theo Tenant đang tắt; bỏ qua phần theo Tenant.');
        }

        return self::SUCCESS;
    }
}
