<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const OWNER = 'owner';

    protected $fillable = ['name', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function isOwner(): bool
    {
        return $this->key === self::OWNER;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('scope');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withTimestamps();
    }
}
