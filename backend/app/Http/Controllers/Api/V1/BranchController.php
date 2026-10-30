<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * TẠM THỜI: chỉ đọc, chưa phân quyền theo vai trò (RBAC làm ở bước tiếp theo).
 * Dữ liệu luôn giới hạn theo Tenant của người dùng qua TenantScope + RLS; route-model-binding cũng đi qua scope
 * nên chi nhánh của Tenant khác trả 404.
 */
class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Branch::query()->orderBy('code');

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        return BranchResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }
}
