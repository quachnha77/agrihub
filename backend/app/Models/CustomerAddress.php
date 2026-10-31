<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Địa chỉ giao hàng của khách. Không ghi audit từng dòng: CustomerService ghi sự kiện `addresses_changed` kèm trước/sau. */
class CustomerAddress extends Model
{
    use BelongsToTenant;

    protected $fillable = ['label', 'address', 'ward', 'district', 'province', 'is_default', 'sort_order'];

    protected $attributes = ['is_default' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
