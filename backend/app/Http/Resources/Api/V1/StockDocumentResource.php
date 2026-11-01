<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockDocument;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Phiếu kho. Số lượng và tiền trả về dạng chuỗi thập phân đúng như lưu trong database. Giá vốn (`unit_cost`, `value`) chỉ có khi người xem
 * được phép (StockDocumentPolicy::seeCosts); `lines` chỉ có khi đã nạp dòng (xem chi tiết, vừa ghi phiếu).
 *
 * @mixin StockDocument
 */
class StockDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $costs = Gate::allows('seeCosts', StockDocument::class);

        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'type' => $this->type,
            'reason' => $this->reason,
            'note' => $this->note,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'transfer' => $this->whenLoaded('transfer', fn () => $this->transfer === null ? null : ['id' => $this->transfer->uuid, 'code' => $this->transfer->code]),
            'purchase' => $this->whenLoaded('purchaseReceipt', fn () => $this->purchaseReceipt === null ? null : ['id' => $this->purchaseReceipt->uuid, 'code' => $this->purchaseReceipt->code]),
            'sale' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder === null ? null : ['id' => $this->salesOrder->uuid, 'code' => $this->salesOrder->code]),
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer === null ? null : ['id' => $this->performer->uuid, 'name' => $this->performer->name]),
            'performed_at' => $this->performed_at?->toIso8601String(),
            'line_count' => $this->whenCounted('movements'),
            'lines' => $this->whenLoaded('movements', fn () => $this->movements->map(fn ($m) => (new StockMovementResource($m))->resolve($request))->values()),
            $this->mergeWhen($costs && $this->relationLoaded('movements'), fn () => [
                'value' => Decimal::money($this->movements->reduce(fn (string $sum, $m) => Decimal::add($sum, Decimal::mul((string) $m->quantity_delta, (string) $m->unit_cost, 2)), '0')),
            ]),
        ];
    }
}
