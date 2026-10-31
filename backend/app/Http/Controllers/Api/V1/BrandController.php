<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/** Thương hiệu. */
class BrandController extends CatalogListController
{
    protected function model(): string
    {
        return Brand::class;
    }

    protected function nameMax(): int
    {
        return 100;
    }

    protected function label(): string
    {
        return 'Thương hiệu';
    }

    /** Chỉ tính sản phẩm còn hoạt động: sản phẩm đã xóa mềm chỉ mất liên kết khi thương hiệu bị xóa. */
    protected function inUse(Model $item): bool
    {
        return Product::where('brand_id', $item->id)->exists();
    }
}
