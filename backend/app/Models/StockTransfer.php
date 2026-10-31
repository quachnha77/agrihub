<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phiếu chuyển kho: chuyển hàng từ kho này sang kho khác (cùng hoặc khác chi nhánh) trong MỘT bước. Bất biến (trigger chặn sửa/xóa) và không có dòng riêng:
 * các dòng chính là dòng sổ kho của phiếu xuất `transfer_out` (kho gửi); phiếu nhập `transfer_in` (kho nhận) ghi phần ngược lại. Chỉ StockService tạo.
 */
class StockTransfer extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** Phiếu xuất ở kho gửi (CKxxxx-X). */
    public function outDocument(): HasOne
    {
        return $this->hasOne(StockDocument::class, 'transfer_id')->where('type', StockDocument::TRANSFER_OUT);
    }

    /** Phiếu nhập ở kho nhận (CKxxxx-N). */
    public function inDocument(): HasOne
    {
        return $this->hasOne(StockDocument::class, 'transfer_id')->where('type', StockDocument::TRANSFER_IN);
    }

    /** Các dòng hàng của phiếu chuyển = dòng sổ kho của phiếu xuất. */
    public function outMovements(): HasManyThrough
    {
        return $this->hasManyThrough(StockMovement::class, StockDocument::class, 'transfer_id', 'document_id')->where('stock_documents.type', StockDocument::TRANSFER_OUT);
    }
}
