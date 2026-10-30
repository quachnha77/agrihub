<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Đánh giá sức khỏe của việc sao lưu toàn hệ thống: có đang chạy đúng lịch không, bản mới nhất có khôi phục thử đạt không,
 * đã có bản nào được tải về nơi lưu riêng chưa. Một nguồn duy nhất cho API trạng thái, lệnh kiểm tra định kỳ và thông báo.
 */
class PlatformBackupMonitor
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /** Sao lưu toàn hệ thống đang tắt (BACKUP_PLATFORM_ENABLED=false): không đánh giá, không cảnh báo. */
    public const DISABLED = 'disabled';

    public function __construct(
        private TenantContext $context,
        private PlatformBackupService $backups,
        private BackupAlerts $alerts,
        private BackupCrypto $crypto,
    ) {}

    /**
     * @return array{
     *   state: string, enabled: bool, checked_at: string, running: bool,
     *   last_completed: ?array<string, mixed>, last_attempt: ?array<string, mixed>, last_verified: ?array<string, mixed>,
     *   last_downloaded_at: ?string, issues: list<array{code: string, level: string, message: string}>,
     *   schedule: array<string, mixed>, thresholds: array<string, int>
     * }
     */
    public function status(): array
    {
        return $this->context->runAsPlatform(fn () => $this->evaluate()['status']);
    }

    /**
     * Kiểm tra định kỳ: chỉ báo các vấn đề theo TRẠNG THÁI (quá hạn...). Sự kiện (vừa thất bại...) đã được báo ngay lúc xảy ra.
     *
     * @return array{state: string, issues: list<array<string, string>>, notified: int}
     */
    public function check(): array
    {
        $this->backups->markInterrupted(); // bản treo được đánh dấu lỗi (và báo) trước khi đánh giá

        $evaluation = $this->context->runAsPlatform(fn () => $this->evaluate());
        $notified = 0;

        foreach ($evaluation['notify'] as $issue) {
            $notified += $this->alerts->platformIssue($issue);
        }

        return ['state' => $evaluation['status']['state'], 'issues' => $evaluation['status']['issues'], 'notified' => $notified];
    }

    /** @return array{status: array<string, mixed>, notify: list<array{code: string, level: string, message: string}>} */
    private function evaluate(): array
    {
        $cfg = (array) config('backup.alerts');
        $now = now();
        $enabled = (bool) config('backup.platform.enabled');

        $platform = fn () => Backup::query()->where('scope', Backup::SCOPE_PLATFORM);
        $lastCompleted = $platform()->where('status', Backup::STATUS_COMPLETED)->orderByDesc('created_at')->orderByDesc('id')->first();
        $lastAttempt = $platform()->whereIn('status', [Backup::STATUS_COMPLETED, Backup::STATUS_FAILED])->orderByDesc('created_at')->orderByDesc('id')->first();
        $lastVerified = $platform()->whereNotNull('verified_at')->orderByDesc('verified_at')->orderByDesc('id')->first();
        $lastVerifiedOk = $platform()->where('verify_status', 'passed')->orderByDesc('verified_at')->orderByDesc('id')->first();
        $lastDownloadedAt = $this->date($platform()->max('downloaded_at'));
        $oldestCompleted = $this->date($platform()->where('status', Backup::STATUS_COMPLETED)->min('created_at'));

        $issues = []; // mỗi vấn đề: code, level, message, notify (báo định kỳ hay không)

        if ($enabled) {
            $overdueHours = (int) $cfg['overdue_hours'];

            if ($lastCompleted === null) {
                // Chưa từng có bản nào: chỉ quá hạn khi hệ thống đã tồn tại đủ lâu (tính từ Super Admin đầu tiên).
                $since = $this->date(DB::table('users')->where('is_platform_admin', true)->min('created_at'));

                if ($since !== null && $since->lt($now->copy()->subHours($overdueHours))) {
                    $issues[] = $this->issue('backup_overdue', self::CRITICAL, 'Chưa có bản sao lưu toàn hệ thống nào hoàn tất.', true);
                }
            } else {
                $age = $this->ageHours($this->completedAt($lastCompleted), $now);

                if ($age >= $overdueHours) {
                    $issues[] = $this->issue('backup_overdue', self::CRITICAL, "Bản sao lưu toàn hệ thống hoàn tất gần nhất đã cách đây {$age} giờ (quá hạn {$overdueHours} giờ).", true);
                }
            }

            if ($lastAttempt?->status === Backup::STATUS_FAILED && ($lastCompleted === null || $lastAttempt->created_at->gt($lastCompleted->created_at))) {
                $issues[] = $this->issue('last_backup_failed', self::CRITICAL, 'Lần sao lưu gần nhất thất bại: '.$lastAttempt->error, false);
            }

            if ($lastVerified?->verify_status === 'failed') {
                $issues[] = $this->issue('verify_failed', self::CRITICAL, 'Lần khôi phục thử gần nhất không đạt: '.$lastVerified->verify_error, false);
            }

            // Khôi phục thử: chỉ đòi hỏi khi đã có bản đủ cũ để lẽ ra phải được thử.
            $verifyDays = (int) $cfg['verify_overdue_days'];
            $verifyRef = $lastVerifiedOk?->verified_at ?? $oldestCompleted;

            if ($verifyRef !== null && $verifyRef->lt($now->copy()->subDays($verifyDays))) {
                $issues[] = $this->issue('verify_overdue', self::WARNING, $lastVerifiedOk === null
                    ? "Đã hơn {$verifyDays} ngày mà chưa có lần khôi phục thử nào đạt."
                    : "Lần khôi phục thử đạt gần nhất đã quá {$verifyDays} ngày.", true);
            }

            // Nhắc lưu bản sao ngoài máy chủ: bản sao nằm cùng máy với database thì mất máy là mất cả hai.
            $offsiteDays = (int) $cfg['offsite_days'];
            $offsiteRef = $lastDownloadedAt ?? $oldestCompleted;

            if ($offsiteDays > 0 && $lastCompleted !== null && $offsiteRef !== null && $offsiteRef->lt($now->copy()->subDays($offsiteDays))) {
                $issues[] = $this->issue('offsite_overdue', self::WARNING, $lastDownloadedAt === null
                    ? 'Chưa có bản sao lưu nào được tải về nơi lưu riêng (bản sao hiện chỉ nằm trên máy chủ). Hãy tải một bản về và cất cùng khóa mã hóa ở nơi khác.'
                    : "Đã hơn {$offsiteDays} ngày chưa tải bản sao lưu nào về nơi lưu riêng.", true);
            }
        }

        // Khóa mã hóa: dùng chung APP_KEY ở production là rủi ro (đổi APP_KEY => mọi bản sao lưu cũ không đọc lại được).
        if ($enabled && app()->environment('production') && ! $this->crypto->hasDedicatedKey()) {
            $issues[] = $this->issue('key_not_dedicated', self::WARNING, 'Đang dùng chung APP_KEY làm khóa mã hóa sao lưu: đổi APP_KEY sẽ làm mọi bản sao lưu cũ không đọc lại được. Hãy đặt BACKUP_ENCRYPTION_KEY riêng (php artisan agrihub:backup:key --generate) và cất ở nơi khác với file sao lưu.', true);
        }

        $state = ! $enabled ? self::DISABLED : match (true) {
            in_array(self::CRITICAL, array_column($issues, 'level'), true) => self::CRITICAL,
            $issues !== [] => self::WARNING,
            default => self::OK,
        };

        return [
            'notify' => array_values(array_map(
                fn (array $i) => ['code' => $i['code'], 'level' => $i['level'], 'message' => $i['message']],
                array_filter($issues, fn (array $i) => $i['notify']),
            )),
            'status' => [
                'state' => $state,
                'enabled' => $enabled,
                'checked_at' => $now->toIso8601String(),
                'running' => $this->backups->hasRunning(),
                'last_completed' => $lastCompleted === null ? null : [
                    'id' => $lastCompleted->uuid,
                    'completed_at' => $this->completedAt($lastCompleted)->toIso8601String(),
                    'age_hours' => $this->ageHours($this->completedAt($lastCompleted), $now),
                    'size_bytes' => $lastCompleted->size_bytes,
                    'verify_status' => $lastCompleted->verify_status,
                    'downloaded_at' => $lastCompleted->downloaded_at?->toIso8601String(),
                ],
                'last_attempt' => $lastAttempt === null ? null : [
                    'id' => $lastAttempt->uuid, 'status' => $lastAttempt->status, 'at' => $this->completedAt($lastAttempt)->toIso8601String(), 'error' => $lastAttempt->error,
                ],
                'last_verified' => $lastVerified === null ? null : [
                    'id' => $lastVerified->uuid, 'status' => $lastVerified->verify_status, 'at' => $lastVerified->verified_at->toIso8601String(), 'error' => $lastVerified->verify_error,
                ],
                'last_downloaded_at' => $lastDownloadedAt?->toIso8601String(),
                'encryption' => [
                    'algorithm' => 'XChaCha20-Poly1305 (libsodium secretstream)',
                    'dedicated_key' => $this->crypto->hasDedicatedKey(),
                    'key_fingerprint' => $this->fingerprint(),
                ],
                'issues' => array_values(array_map(fn (array $i) => ['code' => $i['code'], 'level' => $i['level'], 'message' => $i['message']], $issues)),
                'schedule' => [
                    'backup_at' => config('backup.platform.at'),
                    'prune_at' => config('backup.platform.prune_at'),
                    'verify_day' => config('backup.platform.verify_day'),
                    'verify_at' => config('backup.platform.verify_at'),
                ],
                'thresholds' => [
                    'overdue_hours' => (int) $cfg['overdue_hours'],
                    'verify_overdue_days' => (int) $cfg['verify_overdue_days'],
                    'offsite_days' => (int) $cfg['offsite_days'],
                ],
            ],
        ];
    }

    /** @return array{code: string, level: string, message: string, notify: bool} */
    private function issue(string $code, string $level, string $message, bool $notify): array
    {
        return ['code' => $code, 'level' => $level, 'message' => $message, 'notify' => $notify];
    }

    private function fingerprint(): ?string
    {
        try {
            return $this->crypto->keyFingerprint();
        } catch (\Throwable) {
            return null; // chưa cấu hình khóa nào
        }
    }

    private function ageHours(Carbon $from, Carbon $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 3600));
    }

    private function completedAt(Backup $backup): Carbon
    {
        return $backup->completed_at ?? $backup->created_at;
    }

    private function date(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
