<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một dòng hàng của phiếu bán: số lượng và đơn giá theo đơn vị bán, thành tiền, giá vốn, lô (sản phẩm theo dõi lô). */
class SalesOrderLine extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public $timestamps = false;

    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Các dòng trả hàng của dòng này (số lượng đã trả = tổng `quantity`). */
    public function returnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'order_line_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
