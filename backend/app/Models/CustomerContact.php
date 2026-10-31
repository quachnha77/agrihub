<?php

namespace App\Models;

use App\Support\Auth\Phone;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Người liên hệ của khách (đại lý, hợp tác xã, doanh nghiệp thường có nhiều người). */
class CustomerContact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'phone', 'position', 'note', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : (Phone::normalize($value) ?? trim($value)));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
