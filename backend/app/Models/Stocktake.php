<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một đợt kiểm kê của MỘT kho. `counting` = đang đếm (kho bị khóa: StockService từ chối mọi phiếu khác); `completed` = đã hoàn tất, chênh lệch đã được ghi
 * bằng một phiếu điều chỉnh (nếu có chênh lệch); `cancelled` = đã hủy, tồn không đổi. Đợt đã đóng không sửa nữa. Chỉ StocktakeService thay đổi.
 */
class Stocktake extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const COUNTING = 'counting';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::COUNTING, self::COMPLETED, self::CANCELLED];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    public function adjustmentDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'adjustment_document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }
}
