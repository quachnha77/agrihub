<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;

/** Đơn vị tính (bao, kg, chai, ...). */
class UnitController extends CatalogListController
{
    protected function model(): string
    {
        return Unit::class;
    }

    protected function nameMax(): int
    {
        return 50;
    }

    protected function label(): string
    {
        return 'Đơn vị tính';
    }

    /** Kể cả sản phẩm đã xóa mềm: database vẫn giữ tham chiếu tới đơn vị nên không xóa được. */
    protected function inUse(Model $item): bool
    {
        return Product::withTrashed()->where('base_unit_id', $item->id)->exists() || ProductUnit::where('unit_id', $item->id)->exists();
    }
}
