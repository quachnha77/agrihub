<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phiếu bán hàng: bán từ một kho cho một khách (hoặc khách lẻ). Hàng ra sổ kho qua `stockDocument` (phiếu xuất do StockService ghi); phiếu không sửa/xóa.
 * `paid_amount` là số dẫn xuất (tổng các lần thu chưa hủy trừ tiền đã hoàn khi khách trả hàng), chỉ SalesService cập nhật. Chỉ SalesService tạo.
 * Tiền khách phải trả THỰC = `total` − `returned_amount` (giá trị hàng đã trả lại); mọi số nợ / tình trạng thu tính theo số thực này.
 */
class SalesOrder extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const PAYMENT_STATUSES = [self::UNPAID, self::PARTIAL, self::PAID];

    /** Biểu thức SQL của tiền khách phải trả thực (đã trừ hàng trả lại), dùng khi tính nợ trong truy vấn. */
    public const NET = '(total - returned_amount)';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sold_on' => 'date', 'due_on' => 'date'];
    }

    /** Phiếu mà khách còn nợ (chưa trả đủ phần phải trả thực). */
    public function scopeOwing(Builder $query): Builder
    {
        return $query->whereRaw('paid_amount < '.self::NET);
    }

    /** Tiền khách phải trả thực: tổng phiếu trừ giá trị hàng đã trả lại. */
    public function netTotal(): string
    {
        return bcsub((string) $this->total, (string) $this->returned_amount, 2);
    }

    /** Khách còn nợ phiếu này bao nhiêu. */
    public function balance(): string
    {
        return bcsub($this->netTotal(), (string) $this->paid_amount, 2);
    }

    public function paymentStatus(): string
    {
        return match (true) {
            bccomp((string) $this->paid_amount, $this->netTotal(), 2) >= 0 => self::PAID,
            bccomp((string) $this->paid_amount, '0', 2) > 0 => self::PARTIAL,
            default => self::UNPAID,
        };
    }

    /** Còn nợ và đã quá hạn trả (`due_on` < $today). */
    public function isOverdue(string $today): bool
    {
        return $this->due_on !== null && $this->due_on->toDateString() < $today && bccomp($this->balance(), '0', 2) > 0;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
        return $this->hasMany(SalesOrderLine::class, 'order_id')->orderBy('sort_order')->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'order_id')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class, 'order_id')->orderBy('id');
    }
}
