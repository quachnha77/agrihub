<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCrypto;
use Illuminate\Console\Command;

class BackupKey extends Command
{
    protected $signature = 'agrihub:backup:key
        {--generate : Tạo một khóa mã hóa sao lưu ngẫu nhiên mới để đặt vào BACKUP_ENCRYPTION_KEY}';

    protected $description = 'Xem dấu vân tay của khóa mã hóa sao lưu hiện tại, hoặc tạo khóa mới';

    public function handle(BackupCrypto $crypto): int
    {
        if ($this->option('generate')) {
            $this->line('BACKUP_ENCRYPTION_KEY=base64:'.base64_encode(random_bytes(32)));
            $this->newLine();
            $this->warn('Cất khóa này ở NƠI KHÁC với file sao lưu (trình quản lý mật khẩu + bản giấy). Mất khóa = không đọc lại được bản sao lưu.');
            $this->warn('Đổi khóa không làm hỏng bản cũ nhưng bản cũ vẫn cần KHÓA CŨ để khôi phục: hãy giữ lại mọi khóa đã dùng cùng dấu vân tay của chúng.');

            return self::SUCCESS;
        }

        try {
            $fingerprint = $crypto->keyFingerprint();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Dấu vân tay khóa hiện tại: '.$fingerprint);
        $this->line($crypto->hasDedicatedKey()
            ? 'Nguồn khóa: BACKUP_ENCRYPTION_KEY (riêng cho sao lưu).'
            : 'Nguồn khóa: APP_KEY (dùng chung) — nên đặt BACKUP_ENCRYPTION_KEY riêng: php artisan agrihub:backup:key --generate');
        $this->line('Mỗi bản sao lưu ghi dấu vân tay của khóa đã mã hóa nó (trường key_fingerprint): dùng để biết file nào cần khóa nào.');

        return self::SUCCESS;
    }
}
