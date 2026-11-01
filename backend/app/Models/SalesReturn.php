<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một lần khách trả hàng của một phiếu bán: hàng nhập lại kho qua `stockDocument` (phiếu nhập kho do StockService ghi), giá trị trả lại `total` = trừ vào nợ (`debt_reduction`)
 * + hoàn tiền cho khách (`refund_amount`). Bất biến, chỉ SalesService tạo.
 */
class SalesReturn extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['returned_on' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'return_id')->orderBy('sort_order')->orderBy('id');
    }
}
