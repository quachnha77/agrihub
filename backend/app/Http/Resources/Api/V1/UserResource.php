<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    private bool $withPermissions = false;

    /** Kèm bản đồ quyền hiệu lực (chỉ dùng cho hồ sơ của chính người đăng nhập). */
    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

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
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->uuid, 'key' => $r->key, 'name' => $r->name,
            ])->values()),
            'permissions' => $this->when($this->withPermissions, fn () => (object) $this->permissionMap()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
