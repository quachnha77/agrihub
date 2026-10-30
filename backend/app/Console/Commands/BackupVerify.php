<?php

namespace App\Console\Commands;

use App\Services\Backup\PlatformBackupService;
use Illuminate\Console\Command;

class BackupVerify extends Command
{
    protected $signature = 'agrihub:backup:verify
        {backup? : Mã (uuid) bản sao lưu; bỏ trống = bản toàn hệ thống hoàn tất mới nhất}
        {--scheduled : Do bộ lập lịch gọi: bỏ qua nếu BACKUP_PLATFORM_ENABLED=false}';

    protected $description = 'Kiểm thử khôi phục: nạp bản sao lưu toàn hệ thống vào database tạm, đối chiếu số dòng từng bảng rồi xóa database tạm';

    public function handle(PlatformBackupService $service): int
    {
        if ($this->option('scheduled') && ! config('backup.platform.enabled')) {
            $this->line('Sao lưu toàn hệ thống tự động đang tắt; bỏ qua.');

            return self::SUCCESS;
        }

        $uuid = $this->argument('backup');
        $backup = $uuid ? $service->findByUuid($uuid) : $service->latestCompleted();

        if ($backup === null) {
            $this->error($uuid ? "Không tìm thấy bản sao lưu toàn hệ thống {$uuid}." : 'Chưa có bản sao lưu toàn hệ thống nào để kiểm thử.');

            return self::FAILURE;
        }

        $this->line("Đang khôi phục thử {$backup->uuid} (tạo lúc {$backup->created_at->format('Y-m-d H:i')})…");
        $backup = $service->verify($backup);

        if ($backup->verify_status !== 'passed') {
            $this->error("KHÔNG ĐẠT: {$backup->verify_error}");

            return self::FAILURE;
        }

        $this->info('ĐẠT: khôi phục được và số dòng từng bảng khớp bản gốc.');

        return self::SUCCESS;
    }
}
