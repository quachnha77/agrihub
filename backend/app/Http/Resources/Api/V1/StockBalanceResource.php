<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StockBalance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Số dư theo kho + sản phẩm + lô (đơn vị cơ sở), kèm hạn dùng của lô: `expired` (đã quá hạn) và `days_to_expiry` (âm = đã quá hạn bấy nhiêu ngày).
 *
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expires = $this->batch?->expires_at;
        $today = Carbon::now(config('inventory.timezone'))->startOfDay();
        $days = $expires === null ? null : (int) $today->diffInDays(Carbon::parse($expires->toDateString(), config('inventory.timezone'))->startOfDay(), false);

        return [
            'id' => $this->id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->uuid, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->uuid, 'sku' => $this->product->sku, 'name' => $this->product->name, 'track_batch' => $this->product->track_batch,
                'base_unit' => $this->product->relationLoaded('baseUnit') ? $this->product->baseUnit->name : null,
            ]),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch === null ? null : [
                'id' => $this->batch->uuid, 'batch_no' => $this->batch->batch_no,
                'manufactured_at' => $this->batch->manufactured_at?->toDateString(), 'expires_at' => $expires?->toDateString(),
            ]),
            'quantity' => (string) $this->quantity,
            'expired' => $days !== null && $days < 0,
            'days_to_expiry' => $days,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
