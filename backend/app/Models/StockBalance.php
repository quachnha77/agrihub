<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Số dư dẫn xuất theo kho + sản phẩm + lô (đơn vị cơ sở), không bao giờ âm. KHÔNG sửa trực tiếp: chỉ StockService cập nhật cùng lúc với sổ kho. */
class StockBalance extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $guarded = [];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
