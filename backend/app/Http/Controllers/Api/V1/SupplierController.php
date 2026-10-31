<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SupplierRequest;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Supplier;
use App\Services\Crm\SupplierService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Nhà cung cấp dùng chung toàn doanh nghiệp. Xem/thêm theo quyền `purchase.supplier.*`; sửa/xóa là thao tác cấp doanh nghiệp (SupplierPolicy). */
class SupplierController extends Controller
{
    public function __construct(private SupplierService $suppliers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Supplier::STATUS_ACTIVE, Supplier::STATUS_INACTIVE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Supplier::query()->with('contacts')->orderBy('name')->orderBy('id');

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $digits = preg_replace('/\D/', '', $f['q']);

            $query->where(function (Builder $q) use ($like, $digits) {
                $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('tax_code', 'like', $like);

                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        $query->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        return SupplierResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('view', $supplier);

        return new SupplierResource($supplier->load('contacts'));
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        Gate::authorize('create', Supplier::class);

        return (new SupplierResource($this->suppliers->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        Gate::authorize('update', $supplier);

        return new SupplierResource($this->suppliers->update($supplier, $request->validated()));
    }

    public function destroy(Supplier $supplier): Response
    {
        Gate::authorize('delete', $supplier);

        $this->suppliers->delete($supplier);

        return response()->noContent();
    }
}
