<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupException;
use App\Services\Backup\PlatformBackupService;
use Illuminate\Console\Command;

class BackupRestorePlatform extends Command
{
    protected $signature = 'agrihub:backup:restore-platform
        {backup? : Mã (uuid) bản sao lưu trong danh sách; hoặc dùng --file nếu database đã mất}
        {--file= : Đường dẫn file .agbk (vd. bản đã chép ra nơi lưu ngoài máy chủ)}
        {--target= : Tên database MỚI để khôi phục vào (bắt buộc)}
        {--owner= : Role sở hữu các bảng sau khôi phục (mặc định: tài khoản database của ứng dụng)}
        {--no-owner : Không đặt role sở hữu (các bảng thuộc về tài khoản sao lưu)}
        {--yes : Không hỏi xác nhận}';

    protected $description = 'Khôi phục thảm họa: nạp bản sao lưu toàn hệ thống vào một database MỚI (không bao giờ ghi đè database đang chạy)';

    public function handle(PlatformBackupService $service): int
    {
        $target = (string) $this->option('target');

        if ($target === '') {
            $this->error('Cần --target=<tên database mới>. Lệnh này không bao giờ khôi phục đè lên database đang chạy.');

            return self::FAILURE;
        }

        $backup = null;
        $file = (string) $this->option('file');

        try {
            if ($file !== '') {
                if (! is_file($file)) {
                    throw new BackupException("Không thấy file {$file}.");
                }
            } else {
                $uuid = (string) $this->argument('backup');
                $backup = $uuid !== '' ? $service->findByUuid($uuid) : $service->latestCompleted();

                if ($backup === null) {
                    throw new BackupException('Không tìm thấy bản sao lưu. Chỉ rõ mã bản sao lưu hoặc dùng --file=<đường dẫn>.');
                }

                $file = $service->intactLocalPath($backup);
            }

            $owner = $this->option('no-owner') ? null : ($this->option('owner') ?: (string) config('database.connections.'.config('database.default').'.username'));
            $source = $backup instanceof Backup ? "bản {$backup->uuid} ({$backup->created_at->format('Y-m-d H:i')})" : "file {$file}";
            $owner = $owner === '' ? null : $owner;

            if (! $this->option('yes') && ! $this->confirm("Khôi phục {$source} vào database MỚI '{$target}'?")) {
                $this->line('Đã hủy.');

                return self::FAILURE;
            }

            $manifest = $service->restoreToDatabase($file, $target, $owner, $backup);
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf("Đã khôi phục vào database '%s' (%d bảng, bản gốc tạo lúc %s), số dòng từng bảng khớp.", $target, count($manifest['row_counts']), $manifest['created_at'] ?? '?'));
        $this->line("Bước tiếp theo: kiểm tra dữ liệu, rồi đặt DB_DATABASE={$target} trong .env (hoặc đổi tên database) và khởi động lại ứng dụng + worker.");
        $this->line('Mọi phiên đăng nhập cũ không còn hiệu lực; người dùng cần đăng nhập lại.');

        return self::SUCCESS;
    }
}
