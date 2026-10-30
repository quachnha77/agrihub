<?php

namespace App\Services\Monitoring;

use App\Models\Notice;
use App\Notifications\SystemAlert;
use App\Services\Notifications\AlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cảnh báo hạ tầng cho Super Admin, theo CHUYỂN TRẠNG THÁI: báo ngay khi một thành phần xấu đi, nhắc lại có chu kỳ khi còn
 * tồn tại (nghiêm trọng 6 giờ, cảnh báo 24 giờ) và báo "đã hồi phục" khi trở lại bình thường. Không báo mỗi lần kiểm tra.
 * Sao lưu có cảnh báo riêng (BackupAlerts) nên không báo lại ở đây.
 */
class HealthAlerts
{
    private const SKIP = ['backups'];

    public function __construct(private AlertService $alerts) {}

    /**
     * @param  array{components: list<array<string, mixed>>}  $health  kết quả SystemHealth::evaluate()
     * @return array{alerted: int, recovered: int}
     */
    public function process(array $health): array
    {
        $alerted = 0;
        $recovered = 0;
        $now = now();

        foreach ($health['components'] as $component) {
            $key = (string) $component['key'];
            $status = (string) $component['status'];

            if (in_array($key, self::SKIP, true) || $status === SystemHealth::DISABLED) {
                continue;
            }

            $previous = DB::table('system_health_states')->where('component', $key)->first();
            $previousStatus = $previous->status ?? SystemHealth::OK;

            if ($status === SystemHealth::OK) {
                if ($previousStatus !== SystemHealth::OK) {
                    $this->recovered($component, $previous->since ?? null);
                    $recovered++;
                }

                if ($previous !== null) {
                    DB::table('system_health_states')->where('component', $key)->update(['status' => SystemHealth::OK, 'since' => $now, 'last_alerted_at' => null, 'detail' => null]);
                }

                continue;
            }

            $worse = $previousStatus === SystemHealth::OK || SystemHealth::rank($status) > SystemHealth::rank($previousStatus);
            $remindAfter = (int) config("monitoring.remind_hours.{$status}");
            $lastAlertedAt = isset($previous->last_alerted_at) ? Carbon::parse($previous->last_alerted_at) : null;
            $reminderDue = $previousStatus === $status && ($lastAlertedAt === null || $lastAlertedAt->copy()->addHours($remindAfter)->lte($now));

            if ($worse || $reminderDue) {
                $this->alert($component);
                $alerted++;
                $lastAlertedAt = $now;
            }

            DB::table('system_health_states')->upsert(
                [[
                    'component' => $key,
                    'status' => $status,
                    'since' => $previousStatus === SystemHealth::OK || $previous === null ? $now : $previous->since,
                    'last_alerted_at' => $lastAlertedAt,
                    'detail' => mb_substr((string) $component['summary'], 0, 2000),
                ]],
                ['component'],
                ['status', 'since', 'last_alerted_at', 'detail'],
            );
        }

        return ['alerted' => $alerted, 'recovered' => $recovered];
    }

    /**
     * Khi chính database không dùng được (không lưu được trạng thái/thông báo): chỉ gửi email tới các địa chỉ cấu hình.
     *
     * @param  array<string, mixed>  $component
     */
    public function emergency(array $component): int
    {
        return $this->alerts->toExtraEmails($this->alertFor($component));
    }

    /** @param  array<string, mixed>  $component */
    private function alert(array $component): void
    {
        $this->alerts->toPlatformAdmins($this->alertFor($component));
    }

    /** @param  array<string, mixed>  $component */
    private function alertFor(array $component): SystemAlert
    {
        $critical = $component['status'] === SystemHealth::CRITICAL;
        $lines = [(string) $component['summary']];

        foreach ($component['details'] as $detail) {
            $lines[] = "- {$detail['label']}: {$detail['value']}";
        }

        return new SystemAlert(
            "system.health.{$component['key']}",
            $critical ? Notice::LEVEL_CRITICAL : Notice::LEVEL_WARNING,
            "[Hạ tầng] {$component['label']}: ".($critical ? 'NGHIÊM TRỌNG' : 'cần chú ý'),
            implode("\n", $lines),
            ['component' => $component['key'], 'status' => $component['status']],
        );
    }

    /** @param  array<string, mixed>  $component */
    private function recovered(array $component, ?string $since): void
    {
        $this->alerts->toPlatformAdmins(new SystemAlert(
            "system.health.{$component['key']}",
            Notice::LEVEL_INFO,
            "[Hạ tầng] {$component['label']}: đã hồi phục",
            $component['summary'].($since === null ? '' : "\nBị ảnh hưởng từ ".Carbon::parse($since)->timezone((string) config('backup.alerts.timezone'))->format('H:i d/m/Y').'.'),
            ['component' => $component['key'], 'status' => SystemHealth::OK],
        ));
    }
}
