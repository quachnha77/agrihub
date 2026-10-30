<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\InAppChannel;
use App\Notifications\SystemAlert;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Gửi SystemAlert tới người nhận. Cảnh báo KHÔNG BAO GIỜ được làm hỏng việc đang làm (sao lưu, khôi phục):
 * mỗi kênh gửi độc lập, lỗi (vd. máy chủ mail chết) chỉ được ghi log, kênh còn lại vẫn đi.
 */
class AlertService
{
    public function __construct(private TenantContext $context) {}

    /**
     * Gửi cho mọi Platform Admin đang hoạt động (trong ứng dụng + email) và các địa chỉ BACKUP_ALERT_EMAILS (email).
     * $dedupeKey: cùng khóa chỉ gửi lại sau `backup.alerts.dedupe_hours` giờ (cảnh báo theo trạng thái, không phải theo sự kiện).
     *
     * @return int số người nhận (kể cả địa chỉ email ngoài)
     */
    public function toPlatformAdmins(SystemAlert $alert, ?string $dedupeKey = null): int
    {
        if ($this->suppressed($dedupeKey)) {
            return 0;
        }

        $admins = $this->context->runAsPlatform(fn () => User::query()
            ->where('is_platform_admin', true)->where('status', User::STATUS_ACTIVE)->orderBy('id')->get());

        foreach ($admins as $admin) {
            $this->deliver($admin, $alert, [InAppChannel::class]);
            $this->deliver($admin, $alert, ['mail']);
        }

        $extra = (array) config('backup.alerts.mail_to');

        foreach ($extra as $address) {
            $this->safely(fn () => Notification::route('mail', $address)->notifyNow($alert, ['mail']));
        }

        return $admins->count() + count($extra);
    }

    /** Chỉ trong ứng dụng: thông báo cho một người dùng (vd. người vừa yêu cầu sao lưu). */
    public function toUser(?User $user, SystemAlert $alert, ?string $dedupeKey = null): int
    {
        if ($user === null || $this->suppressed($dedupeKey)) {
            return 0;
        }

        $this->deliver($user, $alert, [InAppChannel::class]);

        return 1;
    }

    /** @param list<string> $channels */
    private function deliver(User $user, SystemAlert $alert, array $channels): void
    {
        $this->safely(fn () => $user->notifyNow($alert, $channels));
    }

    private function suppressed(?string $dedupeKey): bool
    {
        if ($dedupeKey === null) {
            return false;
        }

        // Cache::add chỉ thành công cho lần đầu trong cửa sổ thời gian.
        return ! Cache::add('alert-dedupe:'.sha1($dedupeKey), true, now()->addHours((int) config('backup.alerts.dedupe_hours')));
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
