<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Giá vốn bình quân gia quyền của sản phẩm (mỗi đơn vị cơ sở). Chỉ StockService ghi; dòng này đồng thời là khóa tuần tự hóa tồn của sản phẩm. */
class ProductCost extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
