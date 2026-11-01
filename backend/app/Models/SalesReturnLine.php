<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một dòng của lần trả hàng: dòng phiếu bán gốc, số lượng trả (theo đơn vị bán), giá trị theo giá bán và giá vốn nhập lại kho. */
class SalesReturnLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'order_line_id');
    }
}
