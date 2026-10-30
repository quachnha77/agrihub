<?php

namespace App\Models;

use App\Support\HasPublicUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sổ cái các kỳ thuê bao: chỉ thêm, không sửa/xóa. */
class SubscriptionPeriod extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const UPDATED_AT = null;

    public const KIND_TRIAL = 'trial';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_CHANGE = 'change';

    protected $guarded = ['id', 'uuid', 'tenant_id'];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
