<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Đơn vị tính / nhóm hàng / thương hiệu: cùng một dạng, chỉ nhóm hàng có thêm `sort_order`. */
class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'sort_order' => $this->when(isset($this->sort_order), fn () => $this->sort_order),
        ];
    }
}
