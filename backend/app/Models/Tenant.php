<?php

namespace App\Models;

use App\Services\Rbac\RoleProvisioner;
use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use Auditable, HasFactory, HasPublicUuid, SoftDeletes;

    protected string $auditModule = 'tenant';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'slug', 'phone', 'address', 'tax_code']; // status chỉ đổi qua suspend/activate

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        // Mỗi Tenant mới luôn có đủ vai trò hệ thống (Owner, Admin, ...).
        static::created(fn (Tenant $tenant) => app(RoleProvisioner::class)->provision($tenant));
    }

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
