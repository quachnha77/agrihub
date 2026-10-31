<?php

namespace App\Models;

use App\Support\Auth\Phone;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Người liên hệ của nhà cung cấp. */
class SupplierContact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'phone', 'email', 'position', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : (Phone::normalize($value) ?? trim($value)));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
