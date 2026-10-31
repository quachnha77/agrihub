<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartStocktakeRequest;
use App\Http\Requests\Api\V1\StocktakeCountRequest;
use App\Http\Resources\Api\V1\StocktakeLineResource;
use App\Http\Resources\Api\V1\StocktakeResource;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\Stocktake;
use App\Models\Warehouse;
use App\Services\Inventory\StocktakeService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Kiểm kê kho: bắt đầu một đợt (khóa kho + chụp tồn), xem và ghi số đếm từng dòng, hoàn tất (ghi chênh lệch bằng phiếu điều chỉnh) hoặc hủy.
 * Quyền dùng `inventory.adjustment.*` theo chi nhánh của kho; tồn chỉ thay đổi qua StockService (xem StocktakeService).
 */
class StocktakeController extends Controller
{
    private const WITH = ['warehouse', 'branch', 'starter', 'finisher', 'adjustmentDocument'];

    public function __construct(private StocktakeService $stocktakes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Stocktake::class);

        $f = $request->validate([
            'warehouse' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Stocktake::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Stocktake::query()->with(self::WITH)->withCount($this->counts())->orderByDesc('started_at')->orderByDesc('id');

        if ($request->user()->permissionScope('inventory.adjustment.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        $query->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('warehouse_id', Warehouse::where('uuid', $v)->select('id')));

        return StocktakeResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(Stocktake $stocktake): StocktakeResource
    {
        Gate::authorize('view', $stocktake);

        return $this->present($stocktake);
    }

    /** Bắt đầu một đợt kiểm kê cho kho (khóa kho, chụp tồn hiện có thành các dòng đếm). */
    public function store(StartStocktakeRequest $request): JsonResponse
    {
        $warehouse = $request->warehouse();
        Gate::authorize('create', [Stocktake::class, $warehouse->branch_id]);

        $stocktake = $this->stocktakes->start($warehouse, $request->validated('note'), $request->user());

        return $this->present($stocktake)->response()->setStatusCode(201);
    }

    /** Các dòng đếm của một đợt (phân trang, sắp theo tên sản phẩm rồi hạn dùng của lô). Lọc: `q` (tên, mã, số lô) và `status` (chưa đếm / đã đếm / lệch). */
    public function lines(Request $request, Stocktake $stocktake): AnonymousResourceCollection
    {
        Gate::authorize('view', $stocktake);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['uncounted', 'counted', 'differing'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $stocktake->lines()->getQuery()
            ->whereHas('product')
            ->with(['product.baseUnit', 'product.units.unit', 'batch', 'countedUnit'])
            ->orderBy(Product::query()->select('name')->whereColumn('products.id', 'stocktake_lines.product_id'))
            ->orderBy(Product::query()->select('sku')->whereColumn('products.id', 'stocktake_lines.product_id'))
            ->orderByRaw('(select expires_at from batches where batches.id = stocktake_lines.batch_id) asc nulls last')
            ->orderBy('stocktake_lines.id');

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $query->where(fn ($q) => $q
                ->whereHas('product', fn ($p) => $p->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like))
                ->orWhereHas('batch', fn ($b) => $b->where('batch_no', 'ilike', $like)));
        }

        match ($f['status'] ?? null) {
            'uncounted' => $query->whereNull('counted_quantity'),
            'counted' => $query->whereNotNull('counted_quantity'),
            'differing' => $query->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '<>', 'system_quantity'),
            default => null,
        };

        return StocktakeLineResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    /** Ghi (hoặc xóa) số đếm của một dòng. */
    public function count(StocktakeCountRequest $request, Stocktake $stocktake, string $line): StocktakeLineResource
    {
        Gate::authorize('update', $stocktake);

        $row = $stocktake->lines()->whereHas('product')->where('uuid', $line)->firstOrFail();
        $row = $this->stocktakes->count($stocktake, $row, $request->validated('unit_id'), $request->validated('quantity'), $request->user());

        return new StocktakeLineResource($row->load(['product.baseUnit', 'product.units.unit', 'batch', 'countedUnit']));
    }

    /** Hoàn tất: ghi chênh lệch của các dòng đã đếm bằng MỘT phiếu điều chỉnh rồi mở khóa kho. */
    public function complete(Request $request, Stocktake $stocktake): StocktakeResource
    {
        Gate::authorize('update', $stocktake);

        return $this->present($this->stocktakes->complete($stocktake, $request->user()));
    }

    /** Hủy đợt đang đếm: tồn không đổi, kho được mở khóa. */
    public function cancel(Request $request, Stocktake $stocktake): StocktakeResource
    {
        Gate::authorize('update', $stocktake);

        return $this->present($this->stocktakes->cancel($stocktake, $request->user()));
    }

    /** @return array<int|string, mixed> tham số withCount: tổng dòng, dòng đã đếm, dòng đã đếm mà lệch tồn hệ thống. */
    private function counts(): array
    {
        return [
            'lines',
            'lines as counted_count' => fn ($q) => $q->whereNotNull('counted_quantity'),
            'lines as differing_count' => fn ($q) => $q->whereNotNull('counted_quantity')->whereColumn('counted_quantity', '<>', 'system_quantity'),
        ];
    }

    /** Đợt kèm thống kê dòng và (chỉ người được xem giá vốn) giá trị chênh lệch. */
    private function present(Stocktake $stocktake): StocktakeResource
    {
        $stocktake->load(self::WITH)->loadCount($this->counts());

        if (Gate::allows('seeCosts', StockDocument::class)) {
            $stocktake->setAttribute('difference_value', $this->differenceValue($stocktake));
        }

        return new StocktakeResource($stocktake);
    }

    /**
     * Giá trị chênh lệch (đồng; âm = thiếu hàng). Đang đếm: tính theo giá vốn bình quân hiện tại. Đã hoàn tất: đúng giá trị của phiếu điều chỉnh đã ghi
     * (giá vốn tại thời điểm hoàn tất; 0 nếu không có chênh lệch). Đã hủy: không có.
     */
    private function differenceValue(Stocktake $stocktake): ?string
    {
        if ($stocktake->status === Stocktake::COUNTING) {
            $total = DB::table('stocktake_lines as l')
                ->join('product_costs as c', 'c.product_id', '=', 'l.product_id')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('l.stocktake_id', $stocktake->id)->whereNull('p.deleted_at')->whereNotNull('l.counted_quantity')
                ->selectRaw('COALESCE(SUM((l.counted_quantity - l.system_quantity) * c.avg_cost), 0) as total')->value('total');

            return Decimal::money((string) $total);
        }

        if ($stocktake->status !== Stocktake::COMPLETED) {
            return null;
        }

        $document = $stocktake->adjustmentDocument?->load('movements');

        return Decimal::money($document?->movements->reduce(fn (string $sum, $m) => Decimal::add($sum, Decimal::mul((string) $m->quantity_delta, (string) $m->unit_cost, 2)), '0') ?? '0');
    }
}
