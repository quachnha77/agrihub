<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Resources\Api\V1\CatalogItemResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Khung chung cho các danh sách dùng chung toàn doanh nghiệp (đơn vị tính, nhóm hàng, thương hiệu). Tên duy nhất trong doanh nghiệp
 * không phân biệt hoa/thường; mục đang được dùng không xóa được (chỉ ngừng sử dụng). Quyền do CatalogListPolicy kiểm tra.
 */
abstract class CatalogListController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function nameMax(): int;

    /** Tên gọi trong thông báo lỗi, ví dụ "Đơn vị tính". */
    abstract protected function label(): string;

    /** Có sản phẩm nào đang dùng mục này (kể cả đã xóa mềm nếu database còn ràng buộc) không. */
    abstract protected function inUse(Model $item): bool;

    /** @return array<string, list<mixed>> luật riêng của từng danh sách (ngoài name / is_active) */
    protected function extraRules(): array
    {
        return [];
    }

    protected function orderColumns(): array
    {
        return ['name'];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', $this->model());

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->model()::query();

        foreach ($this->orderColumns() as $column) {
            $query->orderBy($column);
        }

        if (! empty($f['q'])) {
            $query->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%');
        }

        if (array_key_exists('active', $f)) {
            $query->where('is_active', (bool) $f['active']);
        }

        return CatalogItemResource::collection($query->paginate($f['per_page'] ?? 100));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', $this->model());

        $item = new ($this->model())($this->validated($request, null));
        $item->save(); // tenant_id lấy từ context, không từ request

        return (new CatalogItemResource($item->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): CatalogItemResource
    {
        $item = $this->find($id);
        Gate::authorize('update', $item);

        $item->fill($this->validated($request, $item))->save();

        return new CatalogItemResource($item);
    }

    public function destroy(string $id): Response|JsonResponse
    {
        $item = $this->find($id);
        Gate::authorize('delete', $item);

        if ($this->inUse($item)) {
            return Problem::response(409, 'catalog_item_in_use', $this->label().' đang được sản phẩm sử dụng.', $this->label().' đang được sản phẩm sử dụng nên không xóa được. Hãy chuyển sang "ngừng sử dụng" để không chọn lại được nữa.');
        }

        $item->delete();

        return response()->noContent();
    }

    private function find(string $uuid): Model
    {
        return $this->model()::where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Model $current): array
    {
        $partial = $current !== null ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$partial, 'string', 'max:'.$this->nameMax(), $this->uniqueName($current)],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->extraRules(), [], ['name' => 'tên '.mb_strtolower($this->label()), 'is_active' => 'trạng thái', 'sort_order' => 'thứ tự']);
    }

    private function uniqueName(?Model $current): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($current): void {
            $taken = $this->model()::whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($current, fn ($q, Model $own) => $q->where('id', '!=', $own->id))
                ->exists();

            if ($taken) {
                $fail($this->label().' này đã có.');
            }
        };
    }
}
