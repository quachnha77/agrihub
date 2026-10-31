<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kho của MỘT chi nhánh (một chi nhánh có thể có nhiều kho). `tenant_id` và `branch_id` không nằm trong $fillable: chỉ WarehouseController gán,
 * và kho không đổi chi nhánh sau khi tạo (tồn và sổ kho gắn với chi nhánh).
 */
class Warehouse extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected string $auditModule = 'warehouse';

    protected $fillable = ['code', 'name', 'address', 'status'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    public function auditLabel(): ?string
    {
        return $this->code.' - '.$this->name;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
