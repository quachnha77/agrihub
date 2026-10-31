<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CopyProductsRequest;
use App\Http\Requests\Api\V1\ProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use App\Support\Catalog\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Danh mục sản phẩm. Mỗi sản phẩm thuộc MỘT chi nhánh; người dùng chỉ thấy/sửa sản phẩm ở các chi nhánh trong phạm vi quyền
 * `catalog.product.*` (Policy). Route-model-binding đi qua TenantScope nên sản phẩm của doanh nghiệp khác trả 404.
 */
class ProductController extends Controller
{
    private const WITH = ['branch', 'category', 'brand', 'baseUnit', 'units.unit'];

    public function __construct(private ProductService $products) {}

    /** Các loại hàng và trường đặc thù của từng loại, để giao diện dựng form. */
    public function types(): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        return response()->json(['data' => ProductType::describe(), 'meta' => ['max_units' => (int) config('catalog.max_units_per_product')]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ProductType::keys())],
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            'branch' => ['nullable', 'uuid'],
            'category' => ['nullable', 'uuid'],
            'brand' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visible($request)->with(self::WITH)->orderBy('name')->orderBy('id');

        if (! empty($f['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like)
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('product_units')->whereColumn('product_units.product_id', 'products.id')->where('product_units.barcode', $f['q'])));
        }

        $query->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['branch'] ?? null, fn ($q, $v) => $q->whereIn('branch_id', Branch::where('uuid', $v)->select('id')))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->whereIn('category_id', Category::where('uuid', $v)->select('id')))
            ->when($f['brand'] ?? null, fn ($q, $v) => $q->whereIn('brand_id', Brand::where('uuid', $v)->select('id')));

        return ProductResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    public function show(Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return new ProductResource($product->load(self::WITH));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $branch = $request->targetBranch();
        Gate::authorize('create', [Product::class, $branch->id]);

        return (new ProductResource($this->products->create($branch, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        Gate::authorize('update', $product);

        return new ProductResource($this->products->update($product, $request->validated()));
    }

    public function destroy(Product $product): Response
    {
        Gate::authorize('delete', $product);

        $this->products->delete($product);

        return response()->noContent();
    }

    /** Sao chép sản phẩm sang chi nhánh khác: cần xem được sản phẩm nguồn và có quyền tạo ở chi nhánh đích. */
    public function copy(CopyProductsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $target = $request->targetBranch();
        Gate::authorize('create', [Product::class, $target->id]);

        $ids = $request->validated('product_ids');
        $sources = $this->visible($request)->whereIn('uuid', $ids)->orderBy('id')->get();

        // Sản phẩm không tồn tại hoặc ngoài phạm vi của người sao chép đều báo như nhau (không lộ sản phẩm của chi nhánh khác).
        abort_if($sources->count() !== count($ids), 404, 'Không tìm thấy một số sản phẩm cần sao chép.');

        $result = $this->products->copy($sources, $target);

        return response()->json([
            'data' => ProductResource::collection($result['copied']),
            'meta' => ['copied' => $result['copied']->count(), 'skipped' => $result['skipped']],
        ]);
    }

    /** Sản phẩm trong phạm vi quyền xem của người dùng: `tenant` = mọi chi nhánh, `branch` = các chi nhánh được gán. */
    private function visible(Request $request): Builder
    {
        $query = Product::query();

        if ($request->user()->permissionScope('catalog.product.view') !== 'tenant') {
            $query->whereIn('branch_id', $request->user()->branchIds());
        }

        return $query;
    }
}
