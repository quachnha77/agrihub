<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Dùng cho model có cột tenant_id. `tenant_id` KHÔNG được để trong $fillable:
 * khi tạo mới, giá trị luôn lấy từ TenantContext (trừ khi đang chạy ở chế độ nền tảng và đã gán rõ ràng).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($context->isPlatform()) {
                if ($model->tenant_id === null) {
                    throw new LogicException('Cần gán tenant_id rõ ràng khi tạo bản ghi ở chế độ nền tảng.');
                }

                return;
            }

            if ($context->tenantId() === null) {
                throw new LogicException('Không có Tenant context để tạo bản ghi.');
            }

            $model->tenant_id = $context->tenantId();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
