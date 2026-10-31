<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một đơn vị bán của sản phẩm: hệ số quy đổi về đơn vị cơ sở, giá tiền mặt, giá công nợ, mã vạch.
 * Không ghi audit theo từng dòng: ProductService ghi sự kiện `units_changed` kèm giá trước/sau.
 */
class ProductUnit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['factor', 'price_cash', 'price_credit', 'barcode', 'sort_order'];

    protected $attributes = ['factor' => 1, 'price_cash' => 0, 'price_credit' => 0, 'sort_order' => 0];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
