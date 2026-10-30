<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Role;
use App\Services\Rbac\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // `grants_map` có thể được nạp sẵn hàng loạt ở danh sách để tránh N+1.
        $grants = $this->resource->grants_map ?? app(RoleService::class)->grants($this->resource);

        return [
            'id' => $this->uuid,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'users_count' => $this->whenCounted('users'),
            'permissions' => collect($grants)->map(fn ($scope, $key) => ['key' => $key, 'scope' => $scope])->values(),
        ];
    }
}
