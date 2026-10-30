<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditPartitions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class MaintainAuditLogs extends Command
{
    protected $signature = 'agrihub:audit-maintain {--dry-run : Chỉ liệt kê, không tạo/xóa gì}';

    protected $description = 'Tạo sẵn phân vùng audit log cho các tháng tới và (nếu AUDIT_PURGE_ENABLED=true) xóa phân vùng quá thời hạn lưu trữ';

    public function handle(AuditPartitions $partitions, AuditLogger $audit, TenantContext $context): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ahead = (int) config('audit.partitions_ahead');

        if ($dryRun) {
            $this->line('[dry-run] không thay đổi gì.');
        } else {
            $created = $partitions->ensureAhead($ahead);
            $this->info($created === [] ? "Phân vùng cho {$ahead} tháng tới đã đủ." : 'Đã tạo phân vùng: '.implode(', ', $created));
        }

        $retention = (int) config('audit.retention_months');
        $min = (int) config('audit.min_retention_months');

        if (! config('audit.purge_enabled')) {
            $this->line('Xóa phân vùng quá hạn đang TẮT (AUDIT_PURGE_ENABLED=false): không xóa dữ liệu nào.');

            return self::SUCCESS;
        }

        if ($retention < $min) {
            $this->error("AUDIT_RETENTION_MONTHS={$retention} nhỏ hơn mức tối thiểu {$min} tháng; từ chối xóa.");

            return self::FAILURE;
        }

        $dropped = $partitions->purge($retention, $dryRun);

        if ($dropped === []) {
            $this->info("Không có phân vùng nào quá {$retention} tháng.");

            return self::SUCCESS;
        }

        $this->warn(($dryRun ? 'Sẽ xóa' : 'Đã xóa').' phân vùng: '.implode(', ', $dropped));

        if (! $dryRun) {
            // Việc xóa dữ liệu audit tự nó cũng phải để lại dấu vết.
            $context->runAsPlatform(fn () => $audit->record(
                'audit', 'partitions_dropped',
                metadata: ['partitions' => $dropped, 'retention_months' => $retention],
                actor: null,
            ));
        }

        return self::SUCCESS;
    }
}
