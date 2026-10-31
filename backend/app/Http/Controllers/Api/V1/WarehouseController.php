<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Api\V1\WarehouseRequest;
use App\Http\Resources\Api\V1\WarehouseResource;
use App\Models\Branch;
use App\Models\StockDocument;
use App\Models\Stocktake;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Kho của từng chi nhánh (một chi nhánh có nhiều kho). Người dùng chỉ thấy/sửa kho thuộc chi nhánh trong phạm vi quyền `inventory.warehouse.*`. */
class WarehouseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Warehouse::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Warehouse::STATUS_ACTIVE, Warehouse::STATUS_INACTIVE])],
            'branch' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Warehouse::query()->with(['branch', 'openStocktake'])->orderBy('branch_id')->orderBy('code');

        if ($request->user()->permissionScope('inventory.warehouse.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        $query->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['branch'] ?? null, fn ($q, $v) => $q->whereIn('branch_id', Branch::where('uuid', $v)->select('id')));

        return WarehouseResource::collection($query->paginate($f['per_page'] ?? 50)->withQueryString());
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        Gate::authorize('view', $warehouse);

        return new WarehouseResource($warehouse->load(['branch', 'openStocktake']));
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $branch = $request->branch();
        Gate::authorize('create', [Warehouse::class, $branch->id]);

        $warehouse = new Warehouse(Arr::only($request->validated(), ['code', 'name', 'address', 'status']));
        $warehouse->branch_id = $branch->id;
        $warehouse->save(); // tenant_id lấy từ context, không từ request

        return (new WarehouseResource($warehouse->load(['branch', 'openStocktake'])))->response()->setStatusCode(201);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource|JsonResponse
    {
        Gate::authorize('update', $warehouse);

        // Kho đang kiểm kê không được ngừng hoạt động (đợt kiểm kê sẽ không hoàn tất được): phải hoàn tất hoặc hủy đợt trước.
        if (($request->validated('status') ?? null) === Warehouse::STATUS_INACTIVE && $warehouse->openStocktake()->exists()) {
            return Problem::response(409, 'warehouse_counting', 'Kho đang kiểm kê.', 'Kho này đang có đợt kiểm kê chưa kết thúc. Hãy hoàn tất hoặc hủy kiểm kê trước khi chuyển kho sang "ngừng hoạt động".');
        }

        $warehouse->fill(Arr::only($request->validated(), ['code', 'name', 'address', 'status']))->save();

        return new WarehouseResource($warehouse->load(['branch', 'openStocktake']));
    }

    /** Xóa mềm; kho đã có phiếu kho thì không xóa được (sổ kho tham chiếu mãi mãi) — chuyển sang "ngừng hoạt động". */
    public function destroy(Warehouse $warehouse): Response|JsonResponse
    {
        Gate::authorize('delete', $warehouse);

        if (StockDocument::where('warehouse_id', $warehouse->id)->exists()) {
            return Problem::response(409, 'warehouse_in_use', 'Kho đã có phiếu kho.', 'Kho này đã có phiếu nhập/xuất nên không xóa được. Hãy chuyển sang "ngừng hoạt động" để không chọn lại được nữa.');
        }

        if (Stocktake::where('warehouse_id', $warehouse->id)->exists()) {
            return Problem::response(409, 'warehouse_in_use', 'Kho đã có đợt kiểm kê.', 'Kho này đã có đợt kiểm kê nên không xóa được. Hãy chuyển sang "ngừng hoạt động" nếu không dùng nữa.');
        }

        $warehouse->delete();

        return response()->noContent();
    }
}
