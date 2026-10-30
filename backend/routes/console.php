<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tạo sẵn phân vùng audit log cho các tháng tới (và xóa phân vùng quá hạn nếu AUDIT_PURGE_ENABLED=true).
Schedule::command('agrihub:audit-maintain')->dailyAt('02:30')->withoutOverlapping();

// Sao lưu toàn hệ thống (mọi Tenant) bằng pg_dump: chạy hằng ngày, dọn bản cũ, và mỗi tuần khôi phục thử vào database tạm.
// Các lệnh luôn được đăng ký; cờ --scheduled làm chúng bỏ qua khi BACKUP_PLATFORM_ENABLED=false.
Schedule::command('agrihub:backup:platform --scheduled')->dailyAt(config('backup.platform.at'))->withoutOverlapping(360);
// Sao lưu tự động hằng ngày dữ liệu nghiệp vụ của TỪNG Tenant (qua queue worker); bản cũ được dọn cùng lệnh prune.
Schedule::command('agrihub:backup:tenants --scheduled')->dailyAt(config('backup.tenant_auto.at'))->withoutOverlapping(120);
Schedule::command('agrihub:backup:prune --scheduled')->dailyAt(config('backup.platform.prune_at'))->withoutOverlapping();
Schedule::command('agrihub:backup:verify --scheduled')->weeklyOn(config('backup.platform.verify_day'), config('backup.platform.verify_at'))->withoutOverlapping(360);

// Mỗi giờ kiểm tra sức khỏe sao lưu (quá hạn, chưa khôi phục thử, chưa tải bản sao ra ngoài) và thông báo cho Super Admin.
Schedule::command('agrihub:backup:check --scheduled')->hourlyAt(17)->withoutOverlapping();

// Dọn thông báo trong ứng dụng đã cũ.
Schedule::command('agrihub:notices:prune')->dailyAt('03:45')->withoutOverlapping();
