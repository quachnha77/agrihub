<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StocktakeLine;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng đếm: sản phẩm (kèm các đơn vị để người dùng đếm bằng đơn vị tiện nhất), lô (nếu theo dõi lô), tồn hệ thống lúc bắt đầu và số đếm. Số lượng
 * trả về dạng chuỗi thập phân theo ĐƠN VỊ CƠ SỞ; `counted.entered` là đúng số và đơn vị người dùng đã gõ. `difference` = số đếm − tồn hệ thống (âm = thiếu).
 *
 * @mixin StocktakeLine
 */
class StocktakeLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $counted = $this->counted_quantity;

        return [
            'id' => $this->uuid,
            'product' => [
                'id' => $product->uuid,
                'sku' => $product->sku,
                'name' => $product->name,
                'track_batch' => (bool) $product->track_batch,
                'base_unit' => ['id' => $product->baseUnit->uuid, 'name' => $product->baseUnit->name],
                'units' => $product->units->map(fn ($u) => [
                    'unit' => ['id' => $u->unit->uuid, 'name' => $u->unit->name],
                    'is_base' => $u->unit_id === $product->base_unit_id,
                    'factor' => rtrim(rtrim((string) $u->factor, '0'), '.'),
                ])->values(),
            ],
            'batch' => $this->batch === null ? null : ['id' => $this->batch->uuid, 'batch_no' => $this->batch->batch_no, 'expires_at' => $this->batch->expires_at?->toDateString()],
            'system_quantity' => (string) $this->system_quantity,
            'counted' => $counted === null ? null : [
                'quantity' => (string) $counted,
                'entered' => [
                    'quantity' => (string) $this->counted_entered_quantity,
                    'unit' => ['id' => $this->countedUnit->uuid, 'name' => $this->countedUnit->name],
                    'factor' => rtrim(rtrim((string) $this->counted_factor, '0'), '.'),
                ],
            ],
            'difference' => $counted === null ? null : Decimal::round(Decimal::sub((string) $counted, (string) $this->system_quantity), 3),
        ];
    }
}
