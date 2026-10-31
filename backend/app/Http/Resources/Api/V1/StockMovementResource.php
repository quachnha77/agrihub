<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Một dòng sổ kho (thẻ kho). Số lượng theo ĐƠN VỊ CƠ SỞ của sản phẩm (dương = nhập, âm = xuất) kèm đơn vị người dùng đã nhập (`entered`).
 * Giá vốn chỉ có khi người xem được phép (StockDocumentPolicy::seeCosts).
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $costs = Gate::allows('seeCosts', StockDocument::class);

        return [
            'id' => $this->id,
            'type' => $this->movement_type,
            'document' => $this->whenLoaded('document', fn () => ['id' => $this->document->uuid, 'code' => $this->document->code, 'reason' => $this->document->reason]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->uuid, 'sku' => $this->product->sku, 'name' => $this->product->name,
                'base_unit' => $this->product->relationLoaded('baseUnit') ? $this->product->baseUnit->name : null,
            ]),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch === null ? null : [
                'id' => $this->batch->uuid, 'batch_no' => $this->batch->batch_no, 'expires_at' => $this->batch->expires_at?->toDateString(),
            ]),
            'quantity_delta' => (string) $this->quantity_delta,
            'balance_after' => (string) $this->balance_after,
            'entered' => $this->whenLoaded('enteredUnit', fn () => [
                'unit' => $this->enteredUnit->name,
                'quantity' => (string) $this->entered_quantity,
                'factor' => rtrim(rtrim((string) $this->entered_factor, '0'), '.'),
            ]),
            $this->mergeWhen($costs, fn () => [
                'unit_cost' => (string) $this->unit_cost,
                'value' => Decimal::mul((string) $this->quantity_delta, (string) $this->unit_cost, 2),
            ]),
            'performed_at' => $this->performed_at?->toIso8601String(),
        ];
    }
}
