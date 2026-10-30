<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Global scope lớp ứng dụng: giới hạn theo Tenant hiện tại; không có context thì không trả dòng nào. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatform()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');

        if ($context->tenantId() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $context->tenantId());
    }
}
