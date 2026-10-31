<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phiếu nhập hàng từ một nhà cung cấp vào một kho. Hàng vào sổ kho qua `stockDocument` (phiếu nhập kho do StockService ghi); phiếu không sửa/xóa.
 * `paid_amount` là số dẫn xuất (tổng các lần trả chưa hủy), chỉ PurchaseService cập nhật. Chỉ PurchaseService tạo.
 */
class PurchaseReceipt extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const PAYMENT_STATUSES = [self::UNPAID, self::PARTIAL, self::PAID];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    /** Còn nợ nhà cung cấp (tiền hàng chưa trả; chi phí kèm theo không tính vào nợ). */
    public function balance(): string
    {
        return bcsub((string) $this->goods_total, (string) $this->paid_amount, 2);
    }

    public function paymentStatus(): string
    {
        return match (true) {
            bccomp((string) $this->paid_amount, (string) $this->goods_total, 2) >= 0 => self::PAID,
            bccomp((string) $this->paid_amount, '0', 2) > 0 => self::PARTIAL,
            default => self::UNPAID,
        };
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
        return $this->hasMany(PurchaseReceiptLine::class, 'receipt_id')->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class, 'receipt_id')->orderBy('id');
    }
}
