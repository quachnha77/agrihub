<?php

namespace App\Services\Billing;

use App\Http\ApiProblemException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nghiệp vụ thuê bao. Mọi thao tác ghi do nhân viên AgriHub (Platform Admin) thực hiện; chưa có cổng thanh toán tự động,
 * nhân viên ghi nhận khoản thanh toán thủ công khi khách đã chuyển khoản / thanh toán.
 * Các hàm `assert*` dùng được cả trong request của Tenant.
 */
class SubscriptionService
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    /** Gói dùng thử cho Tenant mới. Gọi trong transaction onboarding (đang ở chế độ nền tảng). */
    public function startTrial(Tenant $tenant, ?User $actor): Subscription
    {
        $plan = Plan::where('key', config('billing.default_plan'))->first()
            ?? throw new ApiProblemException(500, 'default_plan_missing', 'Chưa cấu hình gói mặc định.');

        $start = now();
        $end = $start->copy()->addDays((int) config('billing.trial_days'));

        $subscription = new Subscription([
            'plan_id' => $plan->id,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'seats' => (int) config('billing.trial_seats'),
            'unit_price' => $plan->priceFor(Subscription::CYCLE_MONTHLY),
            'max_branches' => $plan->max_branches,
            'storage_mb' => $plan->storage_mb,
            'is_trial' => true,
            'period_start' => $start,
            'period_end' => $end,
        ]);
        $subscription->tenant_id = $tenant->id;
        $subscription->save();

        $this->ledger($subscription, $plan, SubscriptionPeriod::KIND_TRIAL, 0, $actor, ['note' => 'Dùng thử']);
        $this->audit->record('billing', 'trial_started', subject: $subscription, tenantId: $tenant->id, actor: $actor, metadata: [
            'plan' => $plan->key, 'seats' => $subscription->seats, 'period_end' => $end->toIso8601String(),
        ]);

        return $subscription;
    }

    /**
     * Đổi gói / số người dùng / chu kỳ, có hiệu lực ngay và KHÔNG đổi ngày hết hạn (không tính tiền; khoản tiền được
     * ghi khi gia hạn). `unit_price` cho phép chốt giá thương lượng riêng cho khách.
     *
     * @param  array{plan_id?: string, billing_cycle?: string, seats?: int, unit_price?: int}  $data
     */
    public function configure(Subscription $subscription, User $actor, array $data): Subscription
    {
        $plan = isset($data['plan_id']) ? Plan::where('uuid', $data['plan_id'])->first() : $subscription->plan;
        if ($plan === null) {
            throw new ApiProblemException(422, 'validation_failed', 'Dữ liệu không hợp lệ.', ['errors' => ['plan_id' => ['Gói không tồn tại.']]]);
        }

        $planChanged = $plan->id !== $subscription->plan_id;
        if ($planChanged && ! $plan->is_active) {
            throw new ApiProblemException(422, 'plan_inactive', 'Gói này đã ngừng bán.');
        }

        $cycle = $data['billing_cycle'] ?? $subscription->billing_cycle;
        $seats = (int) ($data['seats'] ?? $subscription->seats);
        $repriced = $planChanged || $cycle !== $subscription->billing_cycle;
        $unitPrice = (int) ($data['unit_price'] ?? ($repriced ? $plan->priceFor($cycle) : $subscription->unit_price));
        $maxBranches = $planChanged ? $plan->max_branches : $subscription->max_branches;
        $storage = $planChanged ? $plan->storage_mb : $subscription->storage_mb;

        $this->assertFits($subscription->tenant_id, $seats, $maxBranches);

        $before = $this->snapshot($subscription);

        DB::transaction(function () use ($subscription, $plan, $cycle, $seats, $unitPrice, $maxBranches, $storage, $actor, $before) {
            $subscription->forceFill([
                'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'seats' => $seats, 'unit_price' => $unitPrice,
                'max_branches' => $maxBranches, 'storage_mb' => $storage,
            ])->save();
            $subscription->load('plan');

            $this->ledger($subscription, $plan, SubscriptionPeriod::KIND_CHANGE, 0, $actor);
            $this->audit->record('billing', 'plan_changed', subject: $subscription, tenantId: $subscription->tenant_id, actor: $actor,
                before: $before, after: $this->snapshot($subscription));
        });

        return $subscription->refresh();
    }

    /**
     * Ghi nhận gia hạn. Còn hạn thì nối tiếp từ ngày hết hạn; đã hết hạn thì tính từ hôm nay (không tính bù thời gian đã ngừng dùng).
     *
     * @param  array{periods?: int, amount?: int, paid_at?: ?string, payment_method?: ?string, payment_ref?: ?string, note?: ?string}  $data
     */
    public function renew(Subscription $subscription, User $actor, array $data): Subscription
    {
        $periods = (int) ($data['periods'] ?? 1);
        $plan = $subscription->plan;
        $amount = (int) ($data['amount'] ?? $subscription->unit_price * $subscription->seats * $periods);
        $months = $subscription->billing_cycle === Subscription::CYCLE_YEARLY ? 12 * $periods : $periods;

        $start = $subscription->period_end->isFuture() ? $subscription->period_end->copy() : now();
        $end = $start->copy()->addMonthsNoOverflow($months);
        $before = $this->snapshot($subscription);

        DB::transaction(function () use ($subscription, $plan, $actor, $data, $amount, $start, $end, $before) {
            $subscription->forceFill([
                'is_trial' => false,
                'period_start' => $start,
                'period_end' => $end,
                'last_contacted_at' => null, // việc cần liên hệ của kỳ trước đã xong
                'contact_note' => null,
            ])->save();

            $this->ledger($subscription, $plan, SubscriptionPeriod::KIND_RENEWAL, $amount, $actor, [
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_ref' => $data['payment_ref'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $this->audit->record('billing', 'renewed', subject: $subscription, tenantId: $subscription->tenant_id, actor: $actor,
                before: $before, after: $this->snapshot($subscription),
                metadata: ['amount' => $amount, 'payment_method' => $data['payment_method'] ?? null, 'payment_ref' => $data['payment_ref'] ?? null]);
        });

        return $subscription->refresh();
    }

    /** Nhân viên ghi nhận đã liên hệ khách về việc gia hạn. */
    public function recordContact(Subscription $subscription, User $actor, string $note): Subscription
    {
        $subscription->forceFill(['last_contacted_at' => now(), 'contact_note' => $note])->save();

        $this->audit->record('billing', 'contacted', subject: $subscription, tenantId: $subscription->tenant_id, actor: $actor,
            metadata: ['note' => mb_substr($note, 0, 255)]);

        return $subscription->refresh();
    }

    // --- Giới hạn gói ---------------------------------------------------------------------

    /** Dùng khi tạo / mở khóa người dùng. Tenant chưa có thuê bao thì không giới hạn. */
    public function assertCanAddUser(int $tenantId): void
    {
        $subscription = $this->current($tenantId);

        if ($subscription !== null && $this->activeUsers($tenantId) >= $subscription->seats) {
            throw new ApiProblemException(402, 'seat_limit_reached',
                "Đã dùng hết {$subscription->seats} người dùng của gói. Vui lòng liên hệ AgriHub để nâng cấp.");
        }
    }

    public function assertCanAddBranch(int $tenantId): void
    {
        $subscription = $this->current($tenantId);

        if ($subscription?->max_branches !== null && $this->branches($tenantId) >= $subscription->max_branches) {
            throw new ApiProblemException(402, 'branch_limit_reached',
                "Gói chỉ cho phép tối đa {$subscription->max_branches} chi nhánh. Vui lòng liên hệ AgriHub để nâng cấp.");
        }
    }

    /** @return array{users: int, branches: int} */
    public function usage(int $tenantId): array
    {
        return ['users' => $this->activeUsers($tenantId), 'branches' => $this->branches($tenantId)];
    }

    public function current(int $tenantId): ?Subscription
    {
        return $this->context->runAsPlatform(fn () => Subscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->first());
    }

    // --- nội bộ ---------------------------------------------------------------------------

    private function assertFits(int $tenantId, int $seats, ?int $maxBranches): void
    {
        $usage = $this->usage($tenantId);
        $errors = [];

        if ($seats < $usage['users']) {
            $errors['seats'] = ["Đang có {$usage['users']} người dùng hoạt động, không thể giảm xuống {$seats}."];
        }
        if ($maxBranches !== null && $maxBranches < $usage['branches']) {
            $errors['plan_id'] = ["Đang có {$usage['branches']} chi nhánh, vượt giới hạn {$maxBranches} của gói."];
        }

        if ($errors !== []) {
            throw new ApiProblemException(422, 'subscription_limit_exceeded', 'Cấu hình mới thấp hơn mức đang sử dụng.', ['errors' => $errors]);
        }
    }

    private function activeUsers(int $tenantId): int
    {
        return $this->context->runAsPlatform(fn () => DB::table('users')
            ->where('tenant_id', $tenantId)->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at')->count());
    }

    private function branches(int $tenantId): int
    {
        return $this->context->runAsPlatform(fn () => DB::table('branches')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count());
    }

    /** @param array<string, mixed> $extra */
    private function ledger(Subscription $s, Plan $plan, string $kind, int $amount, ?User $actor, array $extra = []): void
    {
        $row = new SubscriptionPeriod([
            'subscription_id' => $s->id,
            'kind' => $kind,
            'plan_key' => $plan->key,
            'plan_name' => $plan->name,
            'billing_cycle' => $s->billing_cycle,
            'seats' => $s->seats,
            'unit_price' => $s->unit_price,
            'amount' => $amount,
            'period_start' => $s->period_start,
            'period_end' => $s->period_end,
            'recorded_by' => $actor?->id,
        ] + $extra);
        $row->tenant_id = $s->tenant_id;
        $row->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(Subscription $s): array
    {
        return [
            'plan' => $s->plan?->key, 'billing_cycle' => $s->billing_cycle, 'seats' => $s->seats, 'unit_price' => $s->unit_price,
            'is_trial' => $s->is_trial, 'period_end' => $s->period_end->toIso8601String(),
        ];
    }
}
