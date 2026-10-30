<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlanResource;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Cấu hình gói dịch vụ và giá (Super Admin). Không có xóa: gói đã bán còn được thuê bao tham chiếu, nên chỉ ngừng bán.
 * Đổi giá gói KHÔNG đổi giá của khách đang dùng (thuê bao giữ bản chụp giá).
 */
class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,39}$/', Rule::unique('plans', 'key')],
            ...$this->rules(),
        ]);

        $plan = Plan::create($data);

        return (new PlanResource($plan->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan): PlanResource
    {
        $data = $request->validate(['key' => ['prohibited'], ...$this->rules(partial: true)]);

        $plan->fill($data)->save();

        return new PlanResource($plan->refresh());
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_monthly' => [$req, 'integer', 'min:0', 'max:100000000000'],
            'price_yearly' => [$req, 'integer', 'min:0', 'max:100000000000'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'storage_mb' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
