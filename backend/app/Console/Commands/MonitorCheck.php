<?php

namespace App\Console\Commands;

use App\Services\Monitoring\HealthAlerts;
use App\Services\Monitoring\SystemHealth;
use Illuminate\Console\Command;
use Throwable;

class MonitorCheck extends Command
{
    protected $signature = 'agrihub:monitor:check
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu MONITOR_ALERTS_ENABLED=false}
        {--no-notify : Chỉ in tình trạng, không gửi cảnh báo}';

    protected $description = 'Kiểm tra sức khỏe hạ tầng (database, hàng đợi, scheduler, đĩa, cấu hình, sao lưu) và cảnh báo Super Admin khi có thay đổi';

    public function handle(SystemHealth $health, HealthAlerts $alerts): int
    {
        if ($this->option('scheduled') && ! config('monitoring.alerts_enabled')) {
            $this->line('Cảnh báo hạ tầng đang tắt (MONITOR_ALERTS_ENABLED=false); bỏ qua.');

            return self::SUCCESS;
        }

        $result = $health->evaluate();

        $this->line('Tình trạng hệ thống: '.$result['status']);

        foreach ($result['components'] as $component) {
            $this->line(sprintf('  [%s] %s: %s', $component['status'], $component['key'], $component['summary']));
        }

        if (! $this->option('no-notify')) {
            $this->notifyAdmins($result, $alerts);
        }

        // Mã thoát khác 0 khi nghiêm trọng, để công cụ bên ngoài (cron, systemd...) cũng bắt được.
        return $result['status'] === SystemHealth::CRITICAL ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array{components: list<array<string, mixed>>}  $result */
    private function notifyAdmins(array $result, HealthAlerts $alerts): void
    {
        $database = collect($result['components'])->firstWhere('key', 'database');

        // Database không dùng được => không lưu được trạng thái hay thông báo: chỉ email khẩn (tối đa 30 phút một lần).
        if (($database['status'] ?? null) === SystemHealth::CRITICAL) {
            $flag = storage_path('framework/monitor-db-down.flag');

            if (! is_file($flag) || filemtime($flag) < time() - 1800) {
                @touch($flag);
                $this->line('Database không dùng được: gửi email khẩn tới '.$alerts->emergency($database).' địa chỉ.');
            }

            return;
        }

        try {
            $sent = $alerts->process($result);
            $this->line("Cảnh báo mới: {$sent['alerted']}; đã hồi phục: {$sent['recovered']}.");
        } catch (Throwable $e) {
            report($e);
            $this->error('Không xử lý được cảnh báo (xem nhật ký máy chủ).');
        }
    }
}
