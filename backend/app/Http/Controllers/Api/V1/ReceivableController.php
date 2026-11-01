<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SalesPaymentRequest;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Services\Sales\SalesService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Công nợ khách hàng: mỗi khách còn nợ bao nhiêu (có phân theo tuổi nợ) và thu một khoản tiền cho nhiều phiếu cùng lúc. Chỉ đọc từ phiếu bán (`sales_orders`), không có sổ riêng:
 * tổng nợ = Σ (total − returned_amount − paid_amount) của các phiếu còn nợ. Người dùng chỉ thấy / thu các phiếu trong phạm vi quyền `sales.order.*` của mình (khách mua ở nhiều chi nhánh
 * thì mỗi người xem phần nợ thuộc chi nhánh của mình).
 */
class ReceivableController extends Controller
{
    public function __construct(private SalesService $sales) {}

    /**
     * Danh sách khách đang nợ, nợ nhiều nhất trước, kèm `summary` của TOÀN BỘ khách khớp bộ lọc: tổng nợ, số khách nợ và nợ chia theo tuổi
     * (chưa đến hạn / quá hạn 1–30 / 31–60 / trên 60 ngày). Phiếu chưa có hạn trả (khách không có số ngày nợ) tính là chưa đến hạn.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $today = Carbon::now(config('inventory.timezone'));
        $todayDate = $today->toDateString();
        $d30 = $today->copy()->subDays(30)->toDateString();
        $d60 = $today->copy()->subDays(60)->toDateString();

        $open = $this->openOrders($request);

        $filtered = (clone $open)
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
                $q->whereIn('customer_id', Customer::withTrashed()->where(fn ($c) => $c->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('phone', 'ilike', $like))->select('id'));
            })
            ->when($f['overdue'] ?? false, fn ($q) => $q->whereIn('customer_id', (clone $open)->where('due_on', '<', $todayDate)->select('customer_id')));

        $s = (clone $filtered)->toBase()->selectRaw(
            'count(distinct customer_id) as debtors, coalesce(sum(total - returned_amount - paid_amount), 0) as owed,'
            .' coalesce(sum(case when due_on is null or due_on >= ? then total - returned_amount - paid_amount else 0 end), 0) as current,'
            .' coalesce(sum(case when due_on < ? and due_on >= ? then total - returned_amount - paid_amount else 0 end), 0) as d1_30,'
            .' coalesce(sum(case when due_on < ? and due_on >= ? then total - returned_amount - paid_amount else 0 end), 0) as d31_60,'
            .' coalesce(sum(case when due_on < ? then total - returned_amount - paid_amount else 0 end), 0) as d61_plus',
            [$todayDate, $todayDate, $d30, $d30, $d60, $d60],
        )->first();

        $page = (clone $filtered)->toBase()->selectRaw(
            'customer_id, count(*) as open_orders, sum(total - returned_amount - paid_amount) as owed,'
            .' coalesce(sum(case when due_on < ? then total - returned_amount - paid_amount else 0 end), 0) as overdue,'
            .' min(case when due_on < ? then due_on end) as oldest_overdue_on',
            [$todayDate, $todayDate],
        )->groupBy('customer_id')->orderByDesc('owed')->orderBy('customer_id')->paginate($f['per_page'] ?? 20)->withQueryString();

        $customers = Customer::withTrashed()->whereIn('id', collect($page->items())->pluck('customer_id'))->get()->keyBy('id');

        $rows = collect($page->items())->map(function ($row) use ($customers, $today) {
            $customer = $customers->get($row->customer_id);
            $overdue = Decimal::money((string) $row->overdue);

            return [
                'customer' => [
                    'id' => $customer->uuid, 'code' => $customer->code, 'name' => $customer->name, 'phone' => $customer->phone,
                    'status' => $customer->status, 'credit_limit' => Decimal::money((string) $customer->credit_limit), 'credit_days' => $customer->credit_days,
                ],
                'open_orders' => (int) $row->open_orders,
                'owed' => Decimal::money((string) $row->owed),
                'overdue_amount' => $overdue,
                'is_overdue' => Decimal::cmp($overdue, '0') > 0,
                'oldest_overdue_on' => $row->oldest_overdue_on,
                'days_overdue' => $row->oldest_overdue_on !== null ? (int) Carbon::parse($row->oldest_overdue_on, config('inventory.timezone'))->startOfDay()->diffInDays($today->copy()->startOfDay()) : 0,
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
            'summary' => [
                'debtors' => (int) $s->debtors,
                'owed' => Decimal::money((string) $s->owed),
                'aging' => [
                    'current' => Decimal::money((string) $s->current),
                    'overdue_1_30' => Decimal::money((string) $s->d1_30),
                    'overdue_31_60' => Decimal::money((string) $s->d31_60),
                    'overdue_over_60' => Decimal::money((string) $s->d61_plus),
                ],
            ],
        ]);
    }

    /**
     * Thu một khoản của khách, tự trừ vào các phiếu đến hạn sớm nhất trước (chỉ các phiếu người thu được phép thu). Trả về phần đã trừ vào từng phiếu
     * và số khách còn nợ (trong phạm vi người thu) sau khi thu.
     */
    public function collect(SalesPaymentRequest $request, Customer $customer): JsonResponse
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $orders = $this->openOrders($request)->where('customer_id', $customer->id)->get()
            ->filter(fn (SalesOrder $order) => Gate::allows('view', $order) && Gate::allows('collect', $order));

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages(['amount' => ['Khách này không còn phiếu nợ nào mà bạn được thu tiền.']]);
        }

        $v = $request->validated();
        $allocations = $this->sales->collectFromCustomer($customer, $orders->pluck('id')->all(), (string) $v['amount'], $v['method'], $v['paid_on'] ?? null, $v['note'] ?? null, $request->user());

        $remaining = (string) SalesOrder::whereIn('id', $orders->pluck('id'))->owing()->selectRaw('coalesce(sum(total - returned_amount - paid_amount), 0) as owed')->value('owed');

        return response()->json(['data' => [
            'customer' => $customer->uuid,
            'collected' => Decimal::money((string) $v['amount']),
            'remaining' => Decimal::money($remaining),
            'allocations' => collect($allocations)->map(fn (array $a) => [
                'order' => ['id' => $a['order']->uuid, 'code' => $a['order']->code],
                'amount' => Decimal::money($a['amount']),
                'balance' => Decimal::money($a['order']->refresh()->balance()),
            ])->values(),
        ]]);
    }

    /** Các phiếu còn nợ của khách đã chọn (không tính khách lẻ), trong phạm vi xem phiếu bán của người dùng. */
    private function openOrders(Request $request)
    {
        $query = SalesOrder::query()->whereNotNull('customer_id')->owing();

        if ($request->user()->permissionScope('sales.order.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        return $query;
    }
}
