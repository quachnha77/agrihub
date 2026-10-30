<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Tín hiệu sống của các tiến trình nền: mỗi tên một dòng, ghi đè mỗi lần. Đọc/ghi qua đây để không rải truy vấn khắp nơi. */
class Heartbeats
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE_WORKER = 'queue_worker';

    /** @param array<string, mixed> $meta */
    public function beat(string $name, array $meta = []): void
    {
        DB::table('system_heartbeats')->upsert(
            [['name' => $name, 'beat_at' => now(), 'meta' => $meta === [] ? null : json_encode($meta)]],
            ['name'],
            ['beat_at', 'meta'],
        );
    }

    public function last(string $name): ?Carbon
    {
        $at = DB::table('system_heartbeats')->where('name', $name)->value('beat_at');

        return $at === null ? null : Carbon::parse($at);
    }
}
