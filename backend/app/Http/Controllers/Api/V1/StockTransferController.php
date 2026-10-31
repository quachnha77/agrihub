<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockTransferRequest;
use App\Http\Resources\Api\V1\StockTransferResource;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Chuyển kho: danh sách, chi tiết và chuyển hàng từ kho này sang kho khác (cùng hoặc khác chi nhánh) trong MỘT bước. Phiếu đã ghi không sửa/xóa được;
 * chuyển nhầm thì chuyển ngược lại. Tồn chỉ đổi qua StockService (hai phiếu kho đi cặp), xem StockService::transfer.
 */
class StockTransferController extends Controller
{
    private const WITH = ['fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'performer'];

    public function __construct(private StockService $stock) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockTransfer::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'warehouse' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockTransfer::query()->with(self::WITH)->withCount('outMovements')->orderByDesc('performed_at')->orderByDesc('id');

        if ($request->user()->permissionScope('inventory.movement.view') !== 'tenant') {
            $branches = $request->user()->branchIds();
            $query->where(fn ($q) => $q->whereIn('from_branch_id', $branches)->orWhereIn('to_branch_id', $branches));
        }

        $timezone = config('inventory.timezone');
        // Mốc thời gian kèm múi giờ (+07:00): nếu không, máy chủ cơ sở dữ liệu hiểu giờ Việt Nam như giờ UTC và lọc theo ngày lệch 7 tiếng (sáng sớm không thấy phiếu của hôm nay).
        $instant = 'Y-m-d H:i:s.uP';

        $query->when($f['q'] ?? null, fn ($q, $v) => $q->where('code', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['warehouse'] ?? null, function ($q, $v) {
                $id = Warehouse::where('uuid', $v)->select('id');
                $q->where(fn ($w) => $w->whereIn('from_warehouse_id', $id)->orWhereIn('to_warehouse_id', $id));
            })
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('performed_at', '>=', Carbon::parse($v, $timezone)->startOfDay()->format($instant)))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('performed_at', '<=', Carbon::parse($v, $timezone)->endOfDay()->format($instant)));

        return StockTransferResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(StockTransfer $transfer): StockTransferResource
    {
        Gate::authorize('view', $transfer);

        return new StockTransferResource($transfer->load([
            ...self::WITH, 'inDocument',
            'outDocument.movements.product.baseUnit', 'outDocument.movements.batch', 'outDocument.movements.enteredUnit',
        ]));
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        $from = $request->fromWarehouse();
        $to = $request->toWarehouse();
        Gate::authorize('create', [StockTransfer::class, $from->branch_id, $to->branch_id]);

        $transfer = $this->stock->transfer($from, $to, $request->validated(), $request->user());

        return (new StockTransferResource($transfer))->response()->setStatusCode(201);
    }
}
