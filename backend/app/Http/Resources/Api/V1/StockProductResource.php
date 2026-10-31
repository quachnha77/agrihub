<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\StockDocument;
use App\Support\Catalog\ProductType;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Một dòng của trang Tồn kho: sản phẩm kèm tổng tồn (đơn vị cơ sở) ở các kho đang xem, số lượng đã hết hạn / sắp hết hạn, hạn gần nhất và cờ "sắp hết hàng".
 * Các cột tính sẵn (`total_qty`, `expired_qty`, `expiring_qty`, `next_expiry`, `avg_cost`) do StockController chọn kèm khi truy vấn sản phẩm.
 * Giá vốn và giá trị tồn chỉ có khi người xem được phép (StockDocumentPolicy::seeCosts).
 *
 * @mixin Product
 */
class StockProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = (string) $this->total_qty;
        $min = $this->min_stock === null ? null : (string) $this->min_stock;
        $costs = Gate::allows('seeCosts', StockDocument::class);

        return [
            'id' => $this->uuid,
            'sku' => $this->sku,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => ProductType::label($this->type),
            'status' => $this->status,
            'track_batch' => $this->track_batch,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'base_unit' => $this->whenLoaded('baseUnit', fn () => ['id' => $this->baseUnit->uuid, 'name' => $this->baseUnit->name]),
            'total_qty' => $total,
            'min_stock' => $min,
            'is_low' => $min !== null && Decimal::cmp($min, '0') > 0 && Decimal::cmp($total, $min) <= 0,
            'expired_qty' => (string) $this->expired_qty,
            'expiring_qty' => (string) $this->expiring_qty,
            'next_expiry' => $this->next_expiry,
            $this->mergeWhen($costs, fn () => [
                'avg_cost' => $this->avg_cost === null ? null : (string) $this->avg_cost,
                'value' => Decimal::mul($total, (string) ($this->avg_cost ?? '0'), 2),
            ]),
        ];
    }
}
