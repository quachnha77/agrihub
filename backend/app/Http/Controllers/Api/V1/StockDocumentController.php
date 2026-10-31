<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockDocumentRequest;
use App\Http\Resources\Api\V1\StockDocumentResource;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Phiếu kho: danh sách, chi tiết, và ghi phiếu mới (nhập, xuất, điều chỉnh, tồn đầu kỳ). Phiếu đã ghi không sửa/xóa được; sai sót sửa bằng phiếu điều chỉnh. */
class StockDocumentController extends Controller
{
    private const WITH = ['warehouse', 'branch', 'performer', 'transfer', 'purchaseReceipt'];

    public function __construct(private StockService $stock) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockDocument::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::in(StockDocument::ALL_TYPES)],
            'warehouse' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockDocument::query()->with(self::WITH)->withCount('movements')->orderByDesc('performed_at')->orderByDesc('id');

        if ($request->user()->permissionScope('inventory.movement.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        $timezone = config('inventory.timezone');
        // Mốc thời gian kèm múi giờ (+07:00): nếu không, máy chủ cơ sở dữ liệu hiểu giờ Việt Nam như giờ UTC và lọc theo ngày lệch 7 tiếng (sáng sớm không thấy phiếu của hôm nay).
        $instant = 'Y-m-d H:i:s.uP';

        $query->when($f['q'] ?? null, fn ($q, $v) => $q->where('code', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['warehouse'] ?? null, fn ($q, $v) => $q->whereIn('warehouse_id', Warehouse::where('uuid', $v)->select('id')))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('performed_at', '>=', Carbon::parse($v, $timezone)->startOfDay()->format($instant)))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('performed_at', '<=', Carbon::parse($v, $timezone)->endOfDay()->format($instant)));

        return StockDocumentResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(StockDocument $document): StockDocumentResource
    {
        Gate::authorize('view', $document);

        return new StockDocumentResource($document->load([...self::WITH, 'movements.product.baseUnit', 'movements.batch', 'movements.enteredUnit']));
    }

    public function store(StockDocumentRequest $request): JsonResponse
    {
        $warehouse = $request->warehouse();
        Gate::authorize('create', [StockDocument::class, $warehouse->branch_id, $request->validated('type')]);

        $document = $this->stock->post($warehouse, $request->validated('type'), $request->validated(), $request->user());

        return (new StockDocumentResource($document))->response()->setStatusCode(201);
    }
}
