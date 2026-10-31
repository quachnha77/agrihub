<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phiếu kho: nhập kho, xuất kho, điều chỉnh, tồn đầu kỳ. Bất biến (trigger chặn sửa/xóa); sai sót được sửa bằng phiếu điều chỉnh mới.
 * Chỉ StockService tạo.
 */
class StockDocument extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const ADJUSTMENT = 'adjustment';

    public const TYPES = [self::OPENING, self::RECEIPT, self::ISSUE, self::ADJUSTMENT];

    /** Lý do xuất kho hợp lệ; `expired` (hủy hàng hết hạn) là lý do duy nhất được xuất lô đã quá hạn. */
    public const ISSUE_REASONS = ['damaged', 'expired', 'internal_use', 'sample', 'other'];

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

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'document_id')->orderBy('id');
    }
}
