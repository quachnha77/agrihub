<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class NoticesPrune extends Command
{
    protected $signature = 'agrihub:notices:prune
        {--read-days=30 : Xóa thông báo ĐÃ ĐỌC cũ hơn số ngày này}
        {--all-days=90 : Xóa MỌI thông báo cũ hơn số ngày này (kể cả chưa đọc)}';

    protected $description = 'Dọn thông báo trong ứng dụng đã cũ';

    public function handle(TenantContext $context): int
    {
        $readCut = now()->subDays(max(1, (int) $this->option('read-days')));
        $allCut = now()->subDays(max(1, (int) $this->option('all-days')));

        $deleted = $context->runAsPlatform(fn () => Notice::query()
            ->where(fn ($q) => $q->where('created_at', '<', $allCut)
                ->orWhere(fn ($q) => $q->whereNotNull('read_at')->where('created_at', '<', $readCut)))
            ->delete());

        $this->info("Đã xóa {$deleted} thông báo cũ.");

        return self::SUCCESS;
    }
}
