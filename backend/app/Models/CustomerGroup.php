<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Nhóm khách hàng (Đại lý, Nông dân, Khách quen...): chỉ để phân loại và lọc, dùng chung toàn doanh nghiệp. */
class CustomerGroup extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid;

    protected string $auditModule = 'customer';

    protected $fillable = ['name', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): ?string
    {
        return 'Nhóm khách '.$this->name;
    }
}
