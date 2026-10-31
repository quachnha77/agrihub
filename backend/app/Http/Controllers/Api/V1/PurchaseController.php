<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchasePaymentRequest;
use App\Http\Requests\Api\V1\PurchaseReceiptRequest;
use App\Http\Requests\Api\V1\VoidPurchasePaymentRequest;
use App\Http\Resources\Api\V1\PurchaseReceiptResource;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Purchase\PurchaseService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Mua hàng: phiếu nhập hàng từ nhà cung cấp (danh sách, chi tiết, lập phiếu) và trả tiền nhà cung cấp (ghi một lần trả, hủy một lần trả). Phiếu đã lập không sửa/xóa.
 * Hàng vào kho qua StockService (xem PurchaseService); quyền dùng `purchase.order.*` theo chi nhánh của kho nhận.
 */
class PurchaseController extends Controller
{
    private const WITH = ['supplier', 'warehouse', 'branch', 'creator', 'stockDocument'];

    public function __construct(private PurchaseService $purchases) {}

    /** Danh sách mới nhất trước, kèm `summary` (tổng tiền hàng, đã trả, còn nợ) của TOÀN BỘ phiếu khớp bộ lọc, không chỉ trang này. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseReceipt::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'supplier' => ['nullable', 'uuid'],
            'warehouse' => ['nullable', 'uuid'],
            'payment_status' => ['nullable', Rule::in([...PurchaseReceipt::PAYMENT_STATUSES, 'open'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filtered = PurchaseReceipt::query();

        if ($request->user()->permissionScope('purchase.order.view') !== 'tenant') {
            $filtered->whereIn('branch_id', $request->user()->branchIds());
        }

        $filtered->when($f['q'] ?? null, function ($q, $v) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%';
            $q->where(fn ($w) => $w->where('code', 'ilike', $like)->orWhere('supplier_ref', 'ilike', $like));
        })
            ->when($f['supplier'] ?? null, fn ($q, $v) => $q->whereIn('supplier_id', Supplier::withTrashed()->where('uuid', $v)->select('id')))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('warehouse_id', Warehouse::withTrashed()->where('uuid', $v)->select('id')))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('received_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('received_on', '<=', $v));

        match ($f['payment_status'] ?? null) {
            PurchaseReceipt::UNPAID => $filtered->where('paid_amount', 0)->where('goods_total', '>', 0),
            PurchaseReceipt::PARTIAL => $filtered->where('paid_amount', '>', 0)->whereColumn('paid_amount', '<', 'goods_total'),
            PurchaseReceipt::PAID => $filtered->whereColumn('paid_amount', '>=', 'goods_total'),
            'open' => $filtered->whereColumn('paid_amount', '<', 'goods_total'),
            default => null,
        };

        $totals = (clone $filtered)->selectRaw('coalesce(sum(goods_total), 0) as goods, coalesce(sum(paid_amount), 0) as paid')->first();
        $goods = Decimal::money((string) $totals->goods);
        $paid = Decimal::money((string) $totals->paid);

        $page = $filtered->with(self::WITH)->withCount('lines')->orderByDesc('received_on')->orderByDesc('id')->paginate($f['per_page'] ?? 20)->withQueryString();

        return PurchaseReceiptResource::collection($page)->additional(['summary' => ['goods_total' => $goods, 'paid_amount' => $paid, 'balance' => Decimal::money(Decimal::sub($goods, $paid))]]);
    }

    public function show(PurchaseReceipt $purchase): PurchaseReceiptResource
    {
        Gate::authorize('view', $purchase);

        return new PurchaseReceiptResource($this->purchases->loaded($purchase));
    }

    /** Lập phiếu nhập hàng: hàng vào kho ngay (không cần duyệt). Trả tiền ngay (`paid_amount` > 0) cần thêm quyền ghi trả tiền ở chi nhánh của kho. */
    public function store(PurchaseReceiptRequest $request): JsonResponse
    {
        $warehouse = $request->warehouse();
        Gate::authorize('create', [PurchaseReceipt::class, $warehouse->branch_id]);

        if (Decimal::cmp(Decimal::from($request->validated('paid_amount') ?? 0), '0') > 0) {
            Gate::authorize('pay', [PurchaseReceipt::class, $warehouse->branch_id]);
        }

        $receipt = $this->purchases->receive($warehouse, $request->supplier(), $request->validated(), $request->user());

        return (new PurchaseReceiptResource($receipt))->response()->setStatusCode(201);
    }

    /** Ghi thêm một lần trả tiền nhà cung cấp; trả về phiếu đã cập nhật. */
    public function pay(PurchasePaymentRequest $request, PurchaseReceipt $purchase): PurchaseReceiptResource
    {
        Gate::authorize('pay', [PurchaseReceipt::class, $purchase->branch_id]);
        Gate::authorize('view', $purchase);

        $this->purchases->pay($purchase, (string) $request->validated('amount'), $request->validated('method'), $request->validated('paid_on'), $request->validated('note'), $request->user());

        return new PurchaseReceiptResource($this->purchases->loaded($purchase->refresh()));
    }

    /** Hủy một lần trả (trả nhầm); trả về phiếu đã cập nhật. */
    public function voidPayment(VoidPurchasePaymentRequest $request, PurchaseReceipt $purchase, string $payment): PurchaseReceiptResource
    {
        Gate::authorize('voidPayment', $purchase);

        $row = $purchase->payments()->where('uuid', $payment)->firstOrFail();
        $this->purchases->voidPayment($purchase, $row, (string) $request->validated('reason'), $request->user());

        return new PurchaseReceiptResource($this->purchases->loaded($purchase->refresh()));
    }
}
