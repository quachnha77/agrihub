<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->uuid, 'code' => $this->branch->code, 'name' => $this->branch->name]),
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'status' => $this->status,
            // Đợt kiểm kê đang đếm (kho bị khóa: không ghi được phiếu kho khác); null = không khóa. Chỉ có khi đã nạp quan hệ `openStocktake`.
            'stocktake' => $this->whenLoaded('openStocktake', fn () => $this->openStocktake === null ? null : ['id' => $this->openStocktake->uuid, 'code' => $this->openStocktake->code]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
