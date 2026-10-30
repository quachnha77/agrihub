<?php

namespace App\Console\Commands;

use App\Jobs\Backup\RunPlatformBackupJob;
use App\Models\Backup;
use App\Services\Backup\PlatformBackupService;
use Illuminate\Console\Command;

class BackupPlatform extends Command
{
    protected $signature = 'agrihub:backup:platform
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu BACKUP_PLATFORM_ENABLED=false, bản tạo ra là loại "tự động"}
        {--queue : Đẩy vào hàng đợi thay vì chạy ngay}';

    protected $description = 'Sao lưu TOÀN HỆ THỐNG (mọi Tenant) bằng pg_dump, mã hóa và lưu vào disk sao lưu';

    public function handle(PlatformBackupService $service): int
    {
        if ($this->option('scheduled') && ! config('backup.platform.enabled')) {
            $this->line('Sao lưu toàn hệ thống tự động đang tắt (BACKUP_PLATFORM_ENABLED=false); bỏ qua.');

            return self::SUCCESS;
        }

        // Dọn trước các bản bị gián đoạn để chúng không chặn lần chạy này.
        $service->markInterrupted();

        if ($service->hasRunning()) {
            $this->error('Đang có một bản sao lưu toàn hệ thống chưa hoàn tất; không chạy chồng.');

            return self::FAILURE;
        }

        $backup = $service->newRecord(null, $this->option('scheduled') ? Backup::KIND_AUTO : Backup::KIND_MANUAL);

        if ($this->option('queue')) {
            RunPlatformBackupJob::dispatch($backup->id);
            $this->info("Đã đưa vào hàng đợi: {$backup->uuid}");

            return self::SUCCESS;
        }

        $backup = $service->run($backup);

        if ($backup->status !== Backup::STATUS_COMPLETED) {
            $this->error("Sao lưu thất bại: {$backup->error}");

            return self::FAILURE;
        }

        $this->info(sprintf('Đã sao lưu %s (%s, %d bảng).', $backup->uuid, $this->size((int) $backup->size_bytes), count($backup->row_counts ?? [])));

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }
}
