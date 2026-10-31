<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StockBalanceResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Http\Resources\Api\V1\StockProductResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Catalog\ProductType;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tra cứu tồn kho (chỉ đọc): tổng quan theo sản phẩm, số dư theo lô, thẻ kho (sổ kho của một sản phẩm), và con số cảnh báo
 * (sắp hết hàng, lô sắp hết hạn / đã hết hạn). Phạm vi theo quyền `inventory.movement.view`: `tenant` = mọi chi nhánh, `branch` = chi nhánh được gán.
 * Mọi số lượng theo ĐƠN VỊ CƠ SỞ của sản phẩm. Giá vốn chỉ trả về cho người được phép (StockDocumentPolicy::seeCosts).
 */
class StockController extends Controller
{
    /** Các bộ lọc dùng chung cho tổng quan, số dư, cảnh báo. */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ProductType::keys())],
            'branch' => ['nullable', 'uuid'],
            'warehouse' => ['nullable', 'uuid'],
            'product' => ['nullable', 'uuid'],
            'stock' => ['nullable', Rule::in(['in', 'out', 'low'])],
            'expiry' => ['nullable', Rule::in(['expired', 'soon'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** Tổng quan tồn theo sản phẩm (mỗi dòng một sản phẩm), có lọc theo kho, loại hàng, sắp hết hàng, lô sắp/đã hết hạn. */
    public function products(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockDocument::class);
        $f = $this->filters($request);

        $query = $this->productQuery($request, $f)->with(['branch', 'baseUnit'])->orderBy('products.name')->orderBy('products.id');

        return StockProductResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    /** Số dư từng lô (và từng kho): dùng để chọn lô khi xuất và để xem lô nào sắp hết hạn. Mặc định chỉ dòng còn tồn. */
    public function balances(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockDocument::class);
        $f = $this->filters($request);

        $today = $this->today();
        $query = $this->balanceQuery($request, $f)->where('stock_balances.quantity', '>', 0)
            ->with(['warehouse', 'product.baseUnit', 'batch'])
            ->leftJoin('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->select('stock_balances.*')
            ->orderByRaw('batches.expires_at asc nulls last')->orderBy('stock_balances.id');

        $days = (int) ($f['days'] ?? config('inventory.expiry_warning_days'));

        match ($f['expiry'] ?? null) {
            'expired' => $query->where('batches.expires_at', '<', $today),
            'soon' => $query->where('batches.expires_at', '>=', $today)->where('batches.expires_at', '<=', Carbon::parse($today)->addDays($days)->toDateString()),
            default => null,
        };

        return StockBalanceResource::collection($query->paginate($f['per_page'] ?? 50)->withQueryString());
    }

    /** Thẻ kho: sổ kho (mới nhất trước), lọc theo sản phẩm, kho, loại phiếu, khoảng ngày. */
    public function movements(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockDocument::class);

        $f = $request->validate([
            'product' => ['nullable', 'uuid'],
            'warehouse' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(StockDocument::ALL_TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockMovement::query()->with(['document', 'warehouse', 'product.baseUnit', 'batch', 'enteredUnit'])->orderByDesc('id');

        if ($request->user()->permissionScope('inventory.movement.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        $timezone = config('inventory.timezone');

        $query->when($f['product'] ?? null, fn ($q, $v) => $q->whereIn('product_id', Product::where('uuid', $v)->select('id')))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('warehouse_id', Warehouse::where('uuid', $v)->select('id')))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('performed_at', '>=', Carbon::parse($v, $timezone)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('performed_at', '<=', Carbon::parse($v, $timezone)->endOfDay()));

        return StockMovementResource::collection($query->paginate($f['per_page'] ?? 30)->withQueryString());
    }

    /**
     * Con số cảnh báo cho trang Tồn kho: số sản phẩm sắp hết hàng, số lô sắp hết hạn và đã hết hạn (còn tồn), giá trị tồn (nếu được xem giá vốn).
     */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockDocument::class);
        $f = $this->filters($request);

        $today = $this->today();
        $days = (int) ($f['days'] ?? config('inventory.expiry_warning_days'));
        $soonEnd = Carbon::parse($today)->addDays($days)->toDateString();

        $batches = fn () => $this->balanceQuery($request, $f)->where('stock_balances.quantity', '>', 0)
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id');

        $data = [
            'low_products' => $this->productQuery($request, [...$f, 'stock' => 'low'])->count(),
            'expired_batches' => $batches()->where('batches.expires_at', '<', $today)->distinct()->count('stock_balances.batch_id'),
            'expiring_batches' => $batches()->where('batches.expires_at', '>=', $today)->where('batches.expires_at', '<=', $soonEnd)->distinct()->count('stock_balances.batch_id'),
            'expiry_warning_days' => $days,
        ];

        if (Gate::allows('seeCosts', StockDocument::class)) {
            $data['stock_value'] = $this->stockValue($request, $f);
        }

        return response()->json(['data' => $data]);
    }

    // --- truy vấn ---------------------------------------------------------------------------------

    private function today(): string
    {
        return Carbon::now(config('inventory.timezone'))->toDateString();
    }

    /** Dòng số dư trong phạm vi quyền của người xem, áp bộ lọc chi nhánh / kho / sản phẩm / loại / từ khóa. */
    private function balanceQuery(Request $request, array $f): Builder
    {
        $query = StockBalance::query();

        if ($request->user()->permissionScope('inventory.movement.view') !== 'tenant') {
            $query->whereIn('stock_balances.branch_id', $request->user()->branchIds());
        }

        $query->when($f['branch'] ?? null, fn ($q, $v) => $q->whereIn('stock_balances.branch_id', Branch::where('uuid', $v)->select('id')))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('stock_balances.warehouse_id', Warehouse::where('uuid', $v)->select('id')))
            ->when($f['product'] ?? null, fn ($q, $v) => $q->whereIn('stock_balances.product_id', Product::where('uuid', $v)->select('id')))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->whereIn('stock_balances.product_id', Product::where('type', $v)->select('id')));

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $query->whereIn('stock_balances.product_id', Product::where(fn ($p) => $p->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like))->select('id'));
        }

        return $query;
    }

    /** Giá trị tồn = Σ (tổng tồn của sản phẩm × giá vốn bình quân), trong phạm vi và bộ lọc đang xem. */
    private function stockValue(Request $request, array $f): string
    {
        $balances = $this->balanceQuery($request, $f)->where('stock_balances.quantity', '>', 0)
            ->select('stock_balances.product_id', DB::raw('sum(stock_balances.quantity) as qty'))->groupBy('stock_balances.product_id');

        $rows = DB::query()->fromSub($balances->toBase(), 'x')->leftJoin('product_costs as pc', 'pc.product_id', '=', 'x.product_id')
            ->get(['x.qty', 'pc.avg_cost']);

        return Decimal::money($rows->reduce(fn (string $sum, $r) => Decimal::add($sum, Decimal::mul((string) $r->qty, (string) ($r->avg_cost ?? '0'), 2)), '0'));
    }

    /**
     * Sản phẩm (trong phạm vi quyền) kèm các cột tính sẵn: tổng tồn, số lượng đã hết hạn / sắp hết hạn, hạn gần nhất, giá vốn bình quân.
     * Cùng các mảnh SQL con dùng cho cột chọn và cho điều kiện lọc nên hai bên luôn khớp nhau.
     */
    private function productQuery(Request $request, array $f): Builder
    {
        $today = $this->today();
        $days = (int) ($f['days'] ?? config('inventory.expiry_warning_days'));
        $soonEnd = Carbon::parse($today)->addDays($days)->toDateString();

        $warehouseId = isset($f['warehouse']) ? Warehouse::where('uuid', $f['warehouse'])->value('id') : null;
        $w = $warehouseId === null ? '' : ' and sb.warehouse_id = ?';
        $wb = $warehouseId === null ? [] : [$warehouseId];
        $join = 'stock_balances sb join batches bt on bt.id = sb.batch_id';

        $total = ["(select coalesce(sum(sb.quantity), 0)::numeric(18,3) from stock_balances sb where sb.product_id = products.id{$w})", $wb];
        $expired = ["(select coalesce(sum(sb.quantity), 0)::numeric(18,3) from {$join} where sb.product_id = products.id and bt.expires_at < ?{$w})", [$today, ...$wb]];
        $expiring = ["(select coalesce(sum(sb.quantity), 0)::numeric(18,3) from {$join} where sb.product_id = products.id and bt.expires_at >= ? and bt.expires_at <= ?{$w})", [$today, $soonEnd, ...$wb]];
        $next = ["(select min(bt.expires_at) from {$join} where sb.product_id = products.id and sb.quantity > 0{$w})", $wb];
        $avg = ['(select pc.avg_cost from product_costs pc where pc.product_id = products.id)', []];

        $query = Product::query()->selectRaw(
            "products.*, {$total[0]} as total_qty, {$expired[0]} as expired_qty, {$expiring[0]} as expiring_qty, {$next[0]} as next_expiry, {$avg[0]} as avg_cost",
            [...$total[1], ...$expired[1], ...$expiring[1], ...$next[1], ...$avg[1]],
        );

        if ($request->user()->permissionScope('inventory.movement.view') !== 'tenant') {
            $query->whereIn('products.branch_id', $request->user()->branchIds());
        }

        $query->when($f['branch'] ?? null, fn ($q, $v) => $q->whereIn('products.branch_id', Branch::where('uuid', $v)->select('id')))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('products.type', $v))
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('products.uuid', $v));

        if (isset($f['warehouse']) && $warehouseId === null) {
            $query->whereRaw('1 = 0'); // kho không có (hoặc ngoài doanh nghiệp)
        }

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $query->where(fn ($p) => $p->where('products.name', 'ilike', $like)->orWhere('products.sku', 'ilike', $like)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('product_units as pu')->whereColumn('pu.product_id', 'products.id')->where('pu.barcode', 'ilike', $like)));
        }

        match ($f['stock'] ?? null) {
            'in' => $query->whereRaw("{$total[0]} > 0", $total[1]),
            'out' => $query->whereRaw("{$total[0]} = 0", $total[1]),
            'low' => $query->whereRaw("products.min_stock is not null and products.min_stock > 0 and {$total[0]} <= products.min_stock", $total[1]),
            default => null,
        };

        match ($f['expiry'] ?? null) {
            'expired' => $query->whereRaw("{$expired[0]} > 0", $expired[1]),
            'soon' => $query->whereRaw("{$expiring[0]} > 0", $expiring[1]),
            default => null,
        };

        return $query;
    }
}
