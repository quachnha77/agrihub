<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần thu tiền của khách cho một phiếu bán. Không xóa: thu nhầm thì hủy (`voided_at`), lần đã hủy không tính vào số đã thu. */
class SalesPayment extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const CASH = 'cash';

    public const BANK_TRANSFER = 'bank_transfer';

    public const METHODS = [self::CASH, self::BANK_TRANSFER];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
