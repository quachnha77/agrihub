<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/** Nhóm hàng. */
class CategoryController extends CatalogListController
{
    protected function model(): string
    {
        return Category::class;
    }

    protected function nameMax(): int
    {
        return 100;
    }

    protected function label(): string
    {
        return 'Nhóm hàng';
    }

    protected function orderColumns(): array
    {
        return ['sort_order', 'name'];
    }

    protected function extraRules(): array
    {
        return ['sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535']];
    }

    /** Chỉ tính sản phẩm còn hoạt động: sản phẩm đã xóa mềm chỉ mất liên kết khi nhóm bị xóa. */
    protected function inUse(Model $item): bool
    {
        return Product::where('category_id', $item->id)->exists();
    }
}
