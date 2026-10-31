<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Catalog\ProductType;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sản phẩm của MỘT chi nhánh (mỗi chi nhánh có danh mục riêng). `tenant_id`, `branch_id`, `type`, `base_unit_id` không nằm trong $fillable:
 * chỉ ProductService gán, và `type` / `base_unit_id` không đổi sau khi tạo.
 */
class Product extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected string $auditModule = 'product';

    protected $fillable = ['sku', 'name', 'description', 'specs', 'track_batch', 'status', 'min_stock'];

    protected $attributes = ['status' => self::STATUS_ACTIVE, 'track_batch' => false];

    protected function casts(): array
    {
        return ['specs' => 'array', 'track_batch' => 'boolean', 'min_stock' => 'decimal:3'];
    }

    public function auditLabel(): ?string
    {
        return $this->sku.' - '.$this->name;
    }

    public function typeLabel(): string
    {
        return ProductType::label($this->type);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** Các đơn vị bán, đơn vị cơ sở (factor = 1) trước rồi tới các đơn vị quy đổi nhỏ → lớn. */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor')->orderBy('id');
    }
}
