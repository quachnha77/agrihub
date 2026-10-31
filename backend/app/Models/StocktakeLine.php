<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng đếm của đợt kiểm kê: một sản phẩm (và một lô nếu sản phẩm theo dõi lô) cùng tồn hệ thống lúc bắt đầu. `counted_quantity` NULL = chưa đếm
 * (dòng chưa đếm giữ nguyên tồn khi hoàn tất). Số lượng quy về đơn vị cơ sở; đơn vị người dùng đã nhập được giữ để hiển thị lại.
 */
class StocktakeLine extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function countedUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'counted_unit_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
