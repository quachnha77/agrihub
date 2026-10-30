<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Support\Facades\Gate;

/** Gói dịch vụ của doanh nghiệp đang đăng nhập (chỉ xem; gia hạn do nhân viên AgriHub thực hiện). */
class SubscriptionController extends Controller
{
    public function show(): SubscriptionResource
    {
        Gate::authorize('billing.subscription.view');

        $subscription = Subscription::query()->with(['plan', 'periods'])->first() // TenantScope
            ?? throw new ApiProblemException(404, 'subscription_not_found', 'Doanh nghiệp chưa có gói dịch vụ.');

        return new SubscriptionResource($subscription);
    }
}
