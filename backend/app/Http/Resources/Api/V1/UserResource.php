<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_platform_admin' => $this->is_platform_admin,
            'status' => $this->status,
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant === null ? null : [
                'id' => $this->tenant->uuid,
                'name' => $this->tenant->name,
                'slug' => $this->tenant->slug,
            ]),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
