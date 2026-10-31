<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Auth\Phone;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Khách hàng: dùng chung toàn doanh nghiệp, có MỘT chi nhánh phụ trách (`branch_id`) để phân quyền theo chi nhánh.
 * `tenant_id`, `branch_id`, `type`, `group_id` không nằm trong $fillable: chỉ CustomerService gán.
 */
class Customer extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const TYPES = ['individual', 'agent', 'cooperative', 'company'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected string $auditModule = 'customer';

    protected $fillable = ['code', 'name', 'phone', 'email', 'tax_code', 'credit_limit', 'credit_days', 'notes', 'status'];

    protected $attributes = ['status' => self::STATUS_ACTIVE, 'credit_limit' => 0];

    protected function casts(): array
    {
        return ['credit_limit' => 'decimal:2', 'credit_days' => 'integer'];
    }

    /** Số điện thoại lưu dạng chuẩn (0912345678) để tìm kiếm khớp mọi cách viết. Chuỗi rỗng thành NULL. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: function (?string $value) {
            $value = $value === null ? null : trim($value);

            return $value === null || $value === '' ? null : (Phone::normalize($value) ?? $value);
        });
    }

    public function auditLabel(): ?string
    {
        return $this->code.' - '.$this->name;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderBy('sort_order')->orderBy('id');
    }
}
