<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Gói dịch vụ (dùng chung toàn hệ thống). Không xóa: chỉ ngừng bán (is_active) vì thuê bao cũ còn tham chiếu. */
class Plan extends Model
{
    use Auditable, HasFactory, HasPublicUuid;

    protected string $auditModule = 'billing';

    protected $fillable = ['key', 'name', 'description', 'price_monthly', 'price_yearly', 'max_branches', 'storage_mb', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): ?string
    {
        return $this->key;
    }

    /** Giá / người dùng / kỳ thanh toán. */
    public function priceFor(string $cycle): int
    {
        return (int) ($cycle === Subscription::CYCLE_YEARLY ? $this->price_yearly : $this->price_monthly);
    }
}
