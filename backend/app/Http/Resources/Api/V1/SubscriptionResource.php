<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 *
 * `platform` = true thêm thông tin nội bộ của nhân viên AgriHub (ghi chú liên hệ).
 */
class SubscriptionResource extends JsonResource
{
    private bool $platform = false;

    public function forPlatform(): static
    {
        $this->platform = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $usage = app(SubscriptionService::class)->usage($this->tenant_id);

        return [
            'state' => $this->state(),
            'read_only' => $this->isReadOnly(),
            'is_trial' => $this->is_trial,
            'plan' => ['key' => $this->plan->key, 'name' => $this->plan->name],
            'billing_cycle' => $this->billing_cycle,
            'seats' => $this->seats,
            'unit_price' => (int) $this->unit_price,
            'max_branches' => $this->max_branches,
            'storage_mb' => $this->storage_mb,
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'grace_ends_at' => $this->period_end->copy()->addDays((int) config('billing.grace_days'))->toIso8601String(),
            'days_left' => $this->daysLeft(),
            'reminder_level' => $this->reminderLevel(),
            'usage' => $usage,
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->map(fn ($p) => [
                'id' => $p->uuid,
                'kind' => $p->kind,
                'plan' => $p->plan_name,
                'billing_cycle' => $p->billing_cycle,
                'seats' => $p->seats,
                'unit_price' => (int) $p->unit_price,
                'amount' => (int) $p->amount,
                'period_start' => $p->period_start->toIso8601String(),
                'period_end' => $p->period_end->toIso8601String(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'payment_method' => $p->payment_method,
                'payment_ref' => $p->payment_ref,
                'note' => $p->note,
            ])->values()),
            'last_contacted_at' => $this->when($this->platform, fn () => $this->last_contacted_at?->toIso8601String()),
            'contact_note' => $this->when($this->platform, fn () => $this->contact_note),
        ];
    }

    /** Bản rút gọn cho banner trong ứng dụng (/auth/me). Chỉ có khi cần nhắc hoặc đã hết hạn. */
    public static function notice(Subscription $s): ?array
    {
        $state = $s->state();

        if ($state === Subscription::STATE_ACTIVE && $s->reminderLevel() === null) {
            return null;
        }

        return [
            'state' => $state,
            'is_trial' => $s->is_trial,
            'days_left' => $s->daysLeft(),
            'period_end' => $s->period_end->toIso8601String(),
            'read_only' => $s->isReadOnly(),
        ];
    }
}
