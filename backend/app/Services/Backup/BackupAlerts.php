<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Notice;
use App\Models\RestoreRun;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SystemAlert;
use App\Services\Notifications\AlertService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Nội dung các thông báo về trạng thái sao lưu / khôi phục và người nhận của từng loại.
 * Mọi phương thức đều "không ném lỗi": thông báo hỏng không được làm hỏng việc sao lưu đang chạy.
 */
class BackupAlerts
{
    public function __construct(private AlertService $alerts, private TenantContext $context) {}

    // --- Toàn hệ thống → Platform Admin ----------------------------------------------------

    public function platformBackupFailed(Backup $backup): void
    {
        $this->safely(function () use ($backup) {
            $last = $this->context->runAsPlatform(fn () => Backup::query()
                ->where('scope', Backup::SCOPE_PLATFORM)->where('status', Backup::STATUS_COMPLETED)
                ->orderByDesc('created_at')->orderByDesc('id')->first());

            $this->alerts->toPlatformAdmins(new SystemAlert(
                'backup.platform.failed', Notice::LEVEL_CRITICAL, 'Sao lưu toàn hệ thống THẤT BẠI',
                "Lý do: {$backup->error}\nBản sao lưu thành công gần nhất: ".($last === null ? 'chưa có' : $this->when($last->completed_at ?? $last->created_at)).'.',
                ['backup' => $backup->uuid, 'last_ok_backup' => $last?->uuid],
            ));
        });
    }

    public function platformVerifyFailed(Backup $backup): void
    {
        $this->safely(fn () => $this->alerts->toPlatformAdmins(new SystemAlert(
            'backup.platform.verify_failed', Notice::LEVEL_CRITICAL, 'Khôi phục thử bản sao lưu KHÔNG đạt',
            "Bản sao lưu {$this->when($backup->completed_at ?? $backup->created_at)} không khôi phục được: {$backup->verify_error}\nBản này chưa đáng tin cậy; hãy sao lưu lại và kiểm tra.",
            ['backup' => $backup->uuid],
        )));
    }

    public function platformInterrupted(int $count): void
    {
        $this->safely(fn () => $this->alerts->toPlatformAdmins(new SystemAlert(
            'backup.platform.interrupted', Notice::LEVEL_CRITICAL, 'Sao lưu toàn hệ thống bị gián đoạn',
            "{$count} lần sao lưu bị treo quá thời gian cho phép (thường do queue worker bị tắt giữa chừng) và đã được đánh dấu lỗi.",
            ['count' => $count],
        )));
    }

    /**
     * Cảnh báo theo trạng thái do PlatformBackupMonitor phát hiện. Cùng một vấn đề chỉ báo lại sau dedupe_hours.
     *
     * @param  array{code: string, level: string, message: string}  $issue
     */
    public function platformIssue(array $issue): int
    {
        $titles = [
            'backup_overdue' => 'Quá hạn sao lưu toàn hệ thống',
            'verify_overdue' => 'Lâu chưa khôi phục thử bản sao lưu',
            'offsite_overdue' => 'Hãy tải một bản sao lưu về nơi lưu riêng',
            'key_not_dedicated' => 'Chưa dùng khóa mã hóa sao lưu riêng',
        ];

        return (int) $this->safely(fn () => $this->alerts->toPlatformAdmins(
            new SystemAlert("backup.platform.{$issue['code']}", $issue['level'], $titles[$issue['code']] ?? $issue['message'], $issue['message'], ['code' => $issue['code']]),
            dedupeKey: "backup.platform.{$issue['code']}",
        ));
    }

    // --- Theo doanh nghiệp → Super Admin -----------------------------------------------------
    // Sao lưu / khôi phục chỉ do Super Admin thực hiện (doanh nghiệp không có chức năng này) nên mọi thông báo đi tới Super Admin,
    // và luôn nêu rõ là của doanh nghiệp nào.

    /** Chỉ cho bản do người dùng yêu cầu (thủ công): tự động/điểm quay lại/tải lên đã có kết quả ngay ở nơi gọi. */
    public function tenantBackupFinished(Backup $backup): void
    {
        if ($backup->kind === Backup::KIND_AUTO) {
            $this->tenantAutoBackupFinished($backup);

            return;
        }

        if ($backup->kind !== Backup::KIND_MANUAL) {
            return;
        }

        $this->safely(function () use ($backup) {
            $ok = $backup->isCompleted();
            $name = $this->tenantName($backup->tenant_id);

            $this->alerts->toUser($this->requester($backup->created_by), new SystemAlert(
                $ok ? 'backup.tenant.completed' : 'backup.tenant.failed',
                $ok ? Notice::LEVEL_INFO : Notice::LEVEL_CRITICAL,
                $ok ? 'Bản sao lưu đã sẵn sàng' : 'Sao lưu thất bại',
                $ok
                    ? "Doanh nghiệp {$name}: bản sao lưu đã hoàn tất (".$this->size((int) $backup->size_bytes).'). Vào chi tiết doanh nghiệp, mục Sao lưu & khôi phục để tải về và lưu riêng.'
                    : "Doanh nghiệp {$name}. Lý do: {$backup->error}",
                ['backup' => $backup->uuid],
            ));
        });
    }

    /** Sao lưu tự động của một doanh nghiệp: thành công thì im lặng; thất bại thì báo Super Admin (tối đa 1 lần/ngày cho mỗi doanh nghiệp). */
    private function tenantAutoBackupFinished(Backup $backup): void
    {
        if ($backup->isCompleted()) {
            return;
        }

        $this->safely(function () use ($backup) {
            $tenant = $this->context->runAsPlatform(fn () => Tenant::find($backup->tenant_id));

            $this->alerts->toPlatformAdmins(new SystemAlert(
                'backup.tenant.auto_failed', Notice::LEVEL_CRITICAL, 'Sao lưu tự động của một doanh nghiệp thất bại',
                'Doanh nghiệp: '.($tenant?->name ?? "#{$backup->tenant_id}")."\nLý do: {$backup->error}",
                ['backup' => $backup->uuid, 'tenant' => $tenant?->uuid],
            ), dedupeKey: "backup.tenant.auto_failed.platform:{$backup->tenant_id}");
        });
    }

    public function tenantRestoreFinished(RestoreRun $run): void
    {
        $this->safely(function () use ($run) {
            $ok = $run->status === RestoreRun::STATUS_COMPLETED;
            $name = $this->tenantName($run->tenant_id);

            $this->alerts->toUser($this->requester($run->performed_by), new SystemAlert(
                $ok ? 'backup.restore.completed' : 'backup.restore.failed',
                $ok ? Notice::LEVEL_INFO : Notice::LEVEL_CRITICAL,
                $ok ? 'Khôi phục dữ liệu hoàn tất' : 'Khôi phục dữ liệu thất bại',
                $ok
                    ? "Doanh nghiệp {$name}: dữ liệu đã được khôi phục từ bản sao lưu. Bản hiện trạng trước khi khôi phục được giữ làm điểm quay lại."
                    : "Doanh nghiệp {$name}: dữ liệu hiện tại được giữ nguyên. Lý do: {$run->error}",
                ['restore_run' => $run->uuid],
            ));
        });
    }

    // --- nội bộ ---------------------------------------------------------------------------

    private function tenantName(?int $tenantId): string
    {
        $name = $tenantId === null ? null : $this->context->runAsPlatform(fn () => Tenant::whereKey($tenantId)->value('name'));

        return is_string($name) ? $name : "#{$tenantId}";
    }

    private function requester(?int $userId): ?User
    {
        return $userId === null ? null : $this->context->runAsPlatform(fn () => User::find($userId));
    }

    private function when(Carbon $at): string
    {
        return $at->copy()->timezone((string) config('backup.alerts.timezone'))->format('H:i d/m/Y');
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }

    private function safely(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
