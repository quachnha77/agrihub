<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ứng dụng ghi thời điểm theo UTC (múi giờ của Laravel) nên phiên PostgreSQL cũng phải là UTC; nếu không, giờ ghi vào bị hiểu theo múi giờ của máy chủ cơ sở dữ liệu
 * và lệch (máy dev ở Việt Nam lệch 7 tiếng, bộ lọc theo ngày sai).
 */
class DatabaseTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_session_runs_in_utc(): void
    {
        $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
    }

    public function test_a_timestamp_written_by_the_app_is_the_same_instant_when_read_back(): void
    {
        $now = now();
        $stored = DB::selectOne('select ?::timestamptz as at', [$now->format('Y-m-d H:i:s')])->at;

        $this->assertSame($now->format('Y-m-d H:i:s'), Carbon::parse($stored)->utc()->format('Y-m-d H:i:s'));
    }
}
