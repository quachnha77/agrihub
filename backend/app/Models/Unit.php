<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Đơn vị tính (bao, kg, chai, ...): danh sách dùng chung toàn doanh nghiệp. */
class Unit extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid;

    protected string $auditModule = 'catalog';

    protected $fillable = ['name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): ?string
    {
        return 'Đơn vị '.$this->name;
    }
}
