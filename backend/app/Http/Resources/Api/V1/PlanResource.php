<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly' => (int) $this->price_monthly,
            'price_yearly' => (int) $this->price_yearly,
            'max_branches' => $this->max_branches,
            'storage_mb' => $this->storage_mb,
            'is_active' => $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
