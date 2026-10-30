<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use BelongsToTenant, HasPublicUuid; // audit bằng sự kiện có ngữ nghĩa (SubscriptionService), không dùng Auditable

    public const CYCLE_MONTHLY = 'monthly';

    public const CYCLE_YEARLY = 'yearly';

    /** Trạng thái suy ra từ thời gian (không lưu): trial | active | grace | reopened | expired. */
    public const STATE_TRIAL = 'trial';

    public const STATE_ACTIVE = 'active';

    public const STATE_GRACE = 'grace';

    public const STATE_REOPENED = 'reopened';

    public const STATE_EXPIRED = 'expired';

    protected $guarded = ['id', 'uuid', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_trial' => 'boolean',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'last_contacted_at' => 'datetime',
            'reopened_until' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class)->orderByDesc('period_start')->orderByDesc('id');
    }

    public function state(): string
    {
        $now = now();

        if ($now->lessThanOrEqualTo($this->period_end)) {
            return $this->is_trial ? self::STATE_TRIAL : self::STATE_ACTIVE;
        }

        if ($this->reopened_until !== null && $now->lessThanOrEqualTo($this->reopened_until)) {
            return self::STATE_REOPENED; // nhân viên AgriHub mở lại tạm thời
        }

        return $now->lessThanOrEqualTo($this->period_end->copy()->addDays((int) config('billing.grace_days')))
            ? self::STATE_GRACE
            : self::STATE_EXPIRED;
    }

    /** Hết hạn (và hết ân hạn, chưa được mở lại): doanh nghiệp không được sử dụng hệ thống. */
    public function isBlocked(): bool
    {
        return $this->state() === self::STATE_EXPIRED;
    }

    /** Số ngày còn lại tới hạn (làm tròn lên; âm khi đã quá hạn). */
    public function daysLeft(): int
    {
        return (int) ceil(($this->period_end->getTimestamp() - now()->getTimestamp()) / 86400);
    }

    /** Mốc nhắc nhỏ nhất đã chạm tới (15/7/3/1) hoặc null nếu còn xa. */
    public function reminderLevel(): ?int
    {
        $days = $this->daysLeft();
        $levels = collect(config('billing.reminder_days'))->filter(fn (int $d) => $days <= $d)->sort();

        return $levels->isEmpty() ? null : $levels->first();
    }
}
