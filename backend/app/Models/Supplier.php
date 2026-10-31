<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Auth\Phone;
use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Nhà cung cấp: dùng chung toàn doanh nghiệp (mọi chi nhánh chọn được khi nhập hàng). */
class Supplier extends Model
{
    use Auditable, BelongsToTenant, HasPublicUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected string $auditModule = 'supplier';

    protected $fillable = ['code', 'name', 'tax_code', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'status'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    protected function casts(): array
    {
        return ['payment_terms_days' => 'integer'];
    }

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

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class)->orderBy('sort_order')->orderBy('id');
    }
}
