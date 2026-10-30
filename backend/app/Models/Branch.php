<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasPublicUuid, SoftDeletes;

    protected string $auditModule = 'branch';

    protected $fillable = ['code', 'name', 'phone', 'address', 'status'];

    protected $attributes = ['status' => 'active'];

    public function auditLabel(): ?string
    {
        return $this->code.' - '.$this->name;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches')->withTimestamps();
    }
}
