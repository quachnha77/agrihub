<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phiếu kho: nhập kho, xuất kho, điều chỉnh, tồn đầu kỳ. Bất biến (trigger chặn sửa/xóa); sai sót được sửa bằng phiếu điều chỉnh mới.
 * Chỉ StockService tạo. Phiếu `transfer_out` / `transfer_in` đi cặp với nhau theo `transfer_id` (StockTransfer).
 */
class StockDocument extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const ADJUSTMENT = 'adjustment';

    /** Chuyển kho đi: phiếu xuất ở kho gửi / phiếu nhập ở kho nhận của một phiếu chuyển (StockTransfer). Không ghi trực tiếp qua API phiếu kho. */
    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    /** Loại phiếu ghi trực tiếp được qua API phiếu kho. */
    public const TYPES = [self::OPENING, self::RECEIPT, self::ISSUE, self::ADJUSTMENT];

    /** Mọi loại phiếu có thể xuất hiện trong sổ kho (để lọc danh sách). */
    public const ALL_TYPES = [...self::TYPES, self::TRANSFER_OUT, self::TRANSFER_IN];

    /** Lý do xuất kho hợp lệ; `expired` (hủy hàng hết hạn) là lý do duy nhất được xuất lô đã quá hạn. */
    public const ISSUE_REASONS = ['damaged', 'expired', 'internal_use', 'sample', 'other'];

    /** Lý do của phiếu xuất do Bán hàng sinh ra; KHÔNG nằm trong ISSUE_REASONS nên người dùng không chọn được khi xuất kho thủ công. */
    public const SALE_REASON = 'sale';

    /** Lý do của phiếu NHẬP kho do Trả hàng sinh ra (khách trả lại hàng đã bán); chỉ hệ thống truyền, người dùng không chọn được. */
    public const SALE_RETURN_REASON = 'sale_return';

    public const ADJUSTMENT_REASONS = ['count_correction', 'damaged', 'expired', 'other'];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    /** Phiếu nhập hàng (Mua hàng) sinh ra phiếu kho này, nếu có. */
    public function purchaseReceipt(): HasOne
    {
        return $this->hasOne(PurchaseReceipt::class);
    }

    /** Phiếu bán hàng (Bán hàng) sinh ra phiếu kho này, nếu có. */
    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
    }

    /** Lần trả hàng (Bán hàng) sinh ra phiếu nhập kho này, nếu có. */
    public function salesReturn(): HasOne
    {
        return $this->hasOne(SalesReturn::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'document_id')->orderBy('id');
    }
}
