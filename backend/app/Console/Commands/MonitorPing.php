<?php

namespace App\Console\Commands;

use App\Services\Monitoring\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class MonitorPing extends Command
{
    protected $signature = 'agrihub:monitor:ping';

    protected $description = 'Gửi tín hiệu "còn sống" tới dịch vụ giám sát bên ngoài (MONITOR_PING_URL) khi hệ thống không ở mức nghiêm trọng';

    public function handle(SystemHealth $health): int
    {
        $url = config('monitoring.ping_url');

        if (! $url) {
            return self::SUCCESS; // chưa cấu hình giám sát bên ngoài
        }

        $critical = $health->evaluate()['status'] === SystemHealth::CRITICAL;

        // Nghiêm trọng: không báo "còn sống". Nếu dịch vụ có URL báo lỗi riêng thì gọi URL đó, còn không thì im lặng
        // để dịch vụ bên ngoài tự phát hiện việc mất tín hiệu.
        $target = $critical ? config('monitoring.ping_fail_url') : $url;

        if (! $target) {
            $this->line('Hệ thống ở mức nghiêm trọng: không gửi tín hiệu còn sống.');

            return self::FAILURE;
        }

        try {
            Http::timeout(5)->retry(1, 500)->get($target)->throw();
        } catch (Throwable $e) {
            $this->error('Không gửi được tín hiệu tới dịch vụ giám sát bên ngoài ('.class_basename($e).').');

            return self::FAILURE;
        }

        return $critical ? self::FAILURE : self::SUCCESS;
    }
}
