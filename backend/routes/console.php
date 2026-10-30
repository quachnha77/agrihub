<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tạo sẵn phân vùng audit log cho các tháng tới (và xóa phân vùng quá hạn nếu AUDIT_PURGE_ENABLED=true).
Schedule::command('agrihub:audit-maintain')->dailyAt('02:30')->withoutOverlapping();
