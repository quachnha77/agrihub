<?php

namespace App\Jobs\Monitoring;

use App\Services\Monitoring\Heartbeats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Chạy mỗi phút qua hàng đợi: chứng minh cả chuỗi scheduler → hàng đợi → worker đang hoạt động. Duy nhất: khi worker
 * chết thì không dồn ứ mỗi phút một việc (khóa tự hết sau 5 phút).
 */
class QueueHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function handle(Heartbeats $beats): void
    {
        $beats->beat(Heartbeats::QUEUE_WORKER, ['host' => gethostname(), 'pid' => getmypid()]);
    }
}
