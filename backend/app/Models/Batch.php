<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lô hàng của một sản phẩm (số lô + hạn dùng). Không sửa/xóa qua API: lô sinh ra khi nhập kho và được sổ kho tham chiếu mãi mãi.
 * Không ghi audit riêng vì phiếu nhập kho đã là bản ghi truy vết.
 */
class Batch extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = ['batch_no', 'manufactured_at', 'expires_at'];

    protected function casts(): array
    {
        return ['manufactured_at' => 'date:Y-m-d', 'expires_at' => 'date:Y-m-d'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
