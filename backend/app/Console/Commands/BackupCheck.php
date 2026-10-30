<?php

namespace App\Console\Commands;

use App\Services\Backup\PlatformBackupMonitor;
use Illuminate\Console\Command;

class BackupCheck extends Command
{
    protected $signature = 'agrihub:backup:check
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu BACKUP_PLATFORM_ENABLED=false}
        {--no-notify : Chỉ in tình trạng, không gửi thông báo}';

    protected $description = 'Kiểm tra sức khỏe sao lưu toàn hệ thống (quá hạn, khôi phục thử, chưa tải bản sao ra ngoài) và gửi thông báo cho Super Admin';

    public function handle(PlatformBackupMonitor $monitor): int
    {
        if ($this->option('scheduled') && ! config('backup.platform.enabled')) {
            $this->line('Sao lưu toàn hệ thống tự động đang tắt; bỏ qua.');

            return self::SUCCESS;
        }

        if ($this->option('no-notify')) {
            $status = $monitor->status();
            $result = ['state' => $status['state'], 'issues' => $status['issues'], 'notified' => 0];
        } else {
            $result = $monitor->check();
        }

        $this->line('Tình trạng sao lưu: '.$result['state']);

        foreach ($result['issues'] as $issue) {
            $this->line(sprintf('  [%s] %s: %s', $issue['level'], $issue['code'], $issue['message']));
        }

        if ($result['notified'] > 0) {
            $this->line("Đã gửi thông báo tới {$result['notified']} người nhận.");
        }

        // Mã thoát khác 0 khi nghiêm trọng, để công cụ giám sát bên ngoài (cron, Uptime Kuma...) cũng bắt được.
        return $result['state'] === PlatformBackupMonitor::CRITICAL ? self::FAILURE : self::SUCCESS;
    }
}
