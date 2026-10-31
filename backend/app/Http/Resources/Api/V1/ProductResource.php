<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tiền và hệ số trả về dạng chuỗi thập phân đúng như lưu trong database ("120000.00"), không qua số thực nên không sai số làm tròn.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $baseUnit = $this->baseUnit;

        return [
            'id' => $this->uuid,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : ['id' => $this->category->uuid, 'name' => $this->category->name]),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand === null ? null : ['id' => $this->brand->uuid, 'name' => $this->brand->name]),
            'description' => $this->description,
            'status' => $this->status,
            'track_batch' => $this->track_batch,
            'min_stock' => $this->min_stock === null ? null : (string) $this->min_stock,
            'specs' => (object) ($this->specs ?? []),
            'base_unit' => $this->whenLoaded('baseUnit', fn () => ['id' => $baseUnit->uuid, 'name' => $baseUnit->name]),
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($u) => [
                'unit' => ['id' => $u->unit->uuid, 'name' => $u->unit->name],
                'is_base' => $u->unit_id === $this->base_unit_id,
                'factor' => rtrim(rtrim((string) $u->factor, '0'), '.'),
                'price_cash' => (string) $u->price_cash,
                'price_credit' => (string) $u->price_credit,
                'barcode' => $u->barcode,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
