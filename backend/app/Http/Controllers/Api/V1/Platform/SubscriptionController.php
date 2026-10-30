<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SubscriptionResource;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Thuê bao của từng doanh nghiệp, do nhân viên AgriHub quản lý thủ công (chưa có thanh toán tự động). */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $billing) {}

    public function show(Tenant $tenant): SubscriptionResource
    {
        return $this->present($this->find($tenant));
    }

    public function update(Request $request, Tenant $tenant): SubscriptionResource
    {
        $data = $request->validate([
            'plan_id' => ['sometimes', 'uuid'],
            'billing_cycle' => ['sometimes', Rule::in([Subscription::CYCLE_MONTHLY, Subscription::CYCLE_YEARLY])],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
        ]);

        return $this->present($this->billing->configure($this->find($tenant), $request->user(), $data));
    }

    public function renew(Request $request, Tenant $tenant): SubscriptionResource
    {
        $data = $request->validate([
            'periods' => ['sometimes', 'integer', 'min:1', 'max:'.config('billing.max_renew_periods')],
            'amount' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:tomorrow'],
            'payment_method' => ['nullable', Rule::in(config('billing.payment_methods'))],
            'payment_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->present($this->billing->renew($this->find($tenant), $request->user(), $data));
    }

    public function contact(Request $request, Tenant $tenant): SubscriptionResource
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']])['note'];

        return $this->present($this->billing->recordContact($this->find($tenant), $request->user(), $note));
    }

    /**
     * Danh sách "việc cần liên hệ": thuê bao sắp hết hạn trong N ngày (mặc định theo mốc nhắc lớn nhất) hoặc đã hết hạn,
     * hạn gần nhất lên trước. Doanh nghiệp đang bị khóa không nằm trong danh sách.
     */
    public function renewals(Request $request): JsonResponse
    {
        $f = $request->validate([
            'within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'contacted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $within = $f['within_days'] ?? max(config('billing.reminder_days'));

        $page = Subscription::query()
            ->with(['plan', 'tenant'])
            ->whereHas('tenant', fn ($q) => $q->where('status', Tenant::STATUS_ACTIVE))
            ->where('period_end', '<=', now()->addDays($within))
            ->when(isset($f['contacted']), fn ($q) => $q->when(
                filter_var($f['contacted'], FILTER_VALIDATE_BOOLEAN),
                fn ($w) => $w->whereNotNull('last_contacted_at'),
                fn ($w) => $w->whereNull('last_contacted_at'),
            ))
            ->orderBy('period_end')
            ->orderBy('id')
            ->paginate($f['per_page'] ?? 20)
            ->withQueryString();

        $owners = User::query()
            ->whereIn('tenant_id', $page->getCollection()->pluck('tenant_id'))
            ->whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id');

        $rows = $page->getCollection()->map(function (Subscription $s) use ($owners) {
            $owner = $owners[$s->tenant_id][0] ?? null;

            return [
                'tenant' => ['id' => $s->tenant->uuid, 'name' => $s->tenant->name, 'phone' => $s->tenant->phone],
                'owner' => $owner === null ? null : ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone],
                'subscription' => (new SubscriptionResource($s))->forPlatform()->resolve(),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    private function find(Tenant $tenant): Subscription
    {
        return Subscription::query()->where('tenant_id', $tenant->id)->with('plan')->firstOrFail();
    }

    private function present(Subscription $subscription): SubscriptionResource
    {
        return (new SubscriptionResource($subscription->load(['plan', 'periods'])))->forPlatform();
    }
}
