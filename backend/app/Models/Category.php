<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Nhóm hàng: danh sách dùng chung toàn doanh nghiệp. */
class Category extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid;

    protected string $auditModule = 'catalog';

    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function auditLabel(): ?string
    {
        return 'Nhóm hàng '.$this->name;
    }
}
