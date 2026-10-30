<?php

namespace App\Console\Commands;

use App\Jobs\Monitoring\QueueHeartbeatJob;
use App\Services\Monitoring\Heartbeats;
use Illuminate\Console\Command;

class MonitorBeat extends Command
{
    protected $signature = 'agrihub:monitor:beat';

    protected $description = 'Ghi tín hiệu sống của scheduler và đưa một việc kiểm tra vào hàng đợi (để biết queue worker còn chạy)';

    public function handle(Heartbeats $beats): int
    {
        $beats->beat(Heartbeats::SCHEDULER, ['host' => gethostname(), 'pid' => getmypid()]);
        QueueHeartbeatJob::dispatch();

        return self::SUCCESS;
    }
}
