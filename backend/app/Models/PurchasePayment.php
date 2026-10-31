<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lần trả tiền nhà cung cấp cho một phiếu nhập hàng. Không xóa: trả nhầm thì hủy (`voided_at`), lần đã hủy không tính vào số đã trả. */
class PurchasePayment extends Model
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

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'receipt_id');
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
