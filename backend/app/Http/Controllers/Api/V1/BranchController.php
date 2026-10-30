<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Api\V1\BranchRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Dữ liệu luôn giới hạn theo Tenant (TenantScope + RLS) và theo phạm vi quyền của người dùng (Policy).
 * Route-model-binding đi qua TenantScope nên chi nhánh của Tenant khác trả 404.
 */
class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Branch::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = Branch::query()->orderBy('code');

        // Phạm vi "branch": chỉ các chi nhánh được gán cho user.
        if ($user->permissionScope('tenant.branch.view') !== 'tenant') {
            $query->whereIn('id', $user->branchIds());
        }

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        return BranchResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return new BranchResource($branch);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        Gate::authorize('create', Branch::class);

        $branch = new Branch($request->validated());
        $branch->save(); // tenant_id lấy từ context, không từ request

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        Gate::authorize('update', $branch);

        $branch->fill($request->validated())->save();

        return new BranchResource($branch);
    }

    public function destroy(Branch $branch): Response|JsonResponse
    {
        Gate::authorize('delete', $branch);

        if (DB::table('user_branches')->where('branch_id', $branch->id)->exists()) {
            return Problem::response(409, 'branch_in_use', 'Chi nhánh còn người dùng được gán. Hãy gỡ họ khỏi chi nhánh trước.');
        }

        $branch->delete();

        return response()->noContent();
    }
}
