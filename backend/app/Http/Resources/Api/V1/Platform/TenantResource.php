<?php

namespace App\Http\Resources\Api\V1\Platform;

use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'phone' => $this->phone,
            'address' => $this->address,
            'tax_code' => $this->tax_code,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'users_count' => $this->whenCounted('users'),
            'branches_count' => $this->whenCounted('branches'),
            'owners' => $this->whenLoaded('owners', fn () => $this->owners->map(fn ($u) => [
                'id' => $u->uuid,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status,
                'must_change_password' => (bool) $u->must_change_password,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->values()),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
