<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một dòng hàng của phiếu nhập hàng: số lượng và đơn giá theo đơn vị người dùng đã nhập, thành tiền, phần chi phí kèm theo phân bổ, lô. */
class PurchaseReceiptLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
