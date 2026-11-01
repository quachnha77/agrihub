<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SalesOrderRequest;
use App\Http\Requests\Api\V1\SalesPaymentRequest;
use App\Http\Requests\Api\V1\SalesReturnRequest;
use App\Http\Requests\Api\V1\VoidSalesPaymentRequest;
use App\Http\Resources\Api\V1\SalesOrderResource;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Sales\SalesService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bán hàng: phiếu bán cho khách (danh sách, chi tiết, lập phiếu) và thu tiền của khách (ghi một lần thu, hủy một lần thu). Phiếu đã lập không sửa/xóa.
 * Hàng ra kho qua StockService (xem SalesService); quyền dùng `sales.order.*` theo chi nhánh của kho bán.
 */
class SalesController extends Controller
{
    private const WITH = ['customer', 'warehouse', 'branch', 'creator', 'stockDocument'];

    public function __construct(private SalesService $sales) {}

    /** Danh sách mới nhất trước, kèm `summary` (tổng phải thu, đã thu, còn nợ) của TOÀN BỘ phiếu khớp bộ lọc, không chỉ trang này. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'customer' => ['nullable', 'uuid'],
            'warehouse' => ['nullable', 'uuid'],
            'payment_status' => ['nullable', Rule::in([...SalesOrder::PAYMENT_STATUSES, 'open', 'overdue'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filtered = SalesOrder::query();

        if ($request->user()->permissionScope('sales.order.view') !== 'tenant') {
            $filtered->whereIn('branch_id', $request->user()->branchIds());
        }

        $filtered->when($f['q'] ?? null, function ($q, $v) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
            $q->where('code', 'ilike', $like);
        })
            ->when($f['customer'] ?? null, fn ($q, $v) => $q->whereIn('customer_id', Customer::withTrashed()->where('uuid', $v)->select('id')))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('warehouse_id', Warehouse::withTrashed()->where('uuid', $v)->select('id')))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('sold_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('sold_on', '<=', $v));

        match ($f['payment_status'] ?? null) {
            SalesOrder::UNPAID => $filtered->where('paid_amount', 0)->whereRaw(SalesOrder::NET.' > 0'),
            SalesOrder::PARTIAL => $filtered->where('paid_amount', '>', 0)->owing(),
            SalesOrder::PAID => $filtered->whereRaw('paid_amount >= '.SalesOrder::NET),
            'open' => $filtered->owing(),
            'overdue' => $filtered->owing()->where('due_on', '<', Carbon::now(config('inventory.timezone'))->toDateString()),
            default => null,
        };

        $totals = (clone $filtered)->selectRaw('coalesce(sum(total - returned_amount), 0) as total, coalesce(sum(paid_amount), 0) as paid')->first();
        $total = Decimal::money((string) $totals->total);
        $paid = Decimal::money((string) $totals->paid);

        $page = $filtered->with(self::WITH)->withCount('lines')->orderByDesc('sold_on')->orderByDesc('id')->paginate($f['per_page'] ?? 20)->withQueryString();

        return SalesOrderResource::collection($page)->additional(['summary' => ['total' => $total, 'paid_amount' => $paid, 'balance' => Decimal::money(Decimal::sub($total, $paid))]]);
    }

    public function show(SalesOrder $sale): SalesOrderResource
    {
        Gate::authorize('view', $sale);

        return new SalesOrderResource($this->sales->loaded($sale));
    }

    /** Lập phiếu bán: hàng ra kho ngay (không cần duyệt). Khách phải thuộc phạm vi người bán được xem; bán chịu bị chặn theo hạn mức công nợ của khách. */
    public function store(SalesOrderRequest $request): JsonResponse
    {
        $warehouse = $request->warehouse();
        Gate::authorize('create', [SalesOrder::class, $warehouse->branch_id]);

        $customer = $request->customer();

        if ($customer !== null && ! Gate::allows('view', $customer)) {
            throw ValidationException::withMessages(['customer_id' => ['Bạn không có quyền bán cho khách hàng này (khách thuộc chi nhánh khác).']]);
        }

        $order = $this->sales->sell($warehouse, $customer, $request->validated(), $request->user());

        return (new SalesOrderResource($order))->response()->setStatusCode(201);
    }

    /** Thu thêm tiền của khách; trả về phiếu đã cập nhật. */
    public function collect(SalesPaymentRequest $request, SalesOrder $sale): SalesOrderResource
    {
        Gate::authorize('view', $sale);
        Gate::authorize('collect', $sale);

        $this->sales->collect($sale, (string) $request->validated('amount'), $request->validated('method'), $request->validated('paid_on'), $request->validated('note'), $request->user());

        return new SalesOrderResource($this->sales->loaded($sale->refresh()));
    }

    /** Khách trả hàng: hàng nhập lại kho, trừ vào nợ hoặc hoàn tiền; trả về phiếu đã cập nhật kèm lần trả mới. */
    public function returnGoods(SalesReturnRequest $request, SalesOrder $sale): JsonResponse
    {
        Gate::authorize('view', $sale);
        Gate::authorize('returnGoods', $sale);

        $order = $this->sales->returnGoods($sale, $request->validated(), $request->user());

        return (new SalesOrderResource($order))->response()->setStatusCode(201);
    }

    /** Hủy một lần thu (thu nhầm); trả về phiếu đã cập nhật. */
    public function voidPayment(VoidSalesPaymentRequest $request, SalesOrder $sale, string $payment): SalesOrderResource
    {
        Gate::authorize('view', $sale);
        Gate::authorize('voidPayment', $sale);

        $row = $sale->payments()->where('uuid', $payment)->firstOrFail();
        $this->sales->voidPayment($sale, $row, (string) $request->validated('reason'), $request->user());

        return new SalesOrderResource($this->sales->loaded($sale->refresh()));
    }
}
