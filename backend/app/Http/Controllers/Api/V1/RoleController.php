<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Api\V1\RoleRequest;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function __construct(private RoleService $roles, private AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()->withCount('users')->orderByDesc('is_system')->orderBy('name')->get();

        // Nạp quyền của tất cả vai trò trong 1 truy vấn.
        $grants = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roles->modelKeys())
            ->get(['rp.role_id', 'p.key', 'rp.scope'])
            ->groupBy('role_id');

        $roles->each(fn (Role $r) => $r->grants_map = ($grants[$r->id] ?? collect())->pluck('scope', 'key')->all());

        return RoleResource::collection($roles);
    }

    public function show(Role $role): RoleResource
    {
        Gate::authorize('view', $role);

        return new RoleResource($role->loadCount('users'));
    }

    public function store(RoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);

        $grants = $request->grants();
        if ($blocked = $this->escalation($request->user(), $grants)) {
            return $blocked;
        }

        $role = DB::transaction(function () use ($request, $grants) {
            $role = new Role($request->safe()->only(['name', 'description']));
            $role->forceFill(['key' => $this->uniqueKey($request->validated('name')), 'is_system' => false])->save();
            $this->roles->setGrants($role, $grants);
            $this->audit->record('rbac', 'role_permissions_changed', subject: $role, before: ['permissions' => []], after: ['permissions' => $grants]);

            return $role;
        });

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(RoleRequest $request, Role $role): RoleResource|JsonResponse
    {
        Gate::authorize('update', $role);

        $grants = $request->has('permissions') ? $request->grants() : null;
        if ($grants !== null && ($blocked = $this->escalation($request->user(), $grants, $role))) {
            return $blocked;
        }

        DB::transaction(function () use ($request, $role, $grants) {
            // Tên/mô tả vai trò hệ thống vẫn sửa được; `key` thì không bao giờ đổi.
            $role->fill($request->safe()->only(['name', 'description']))->save();

            if ($grants !== null) {
                $before = $this->roles->grants($role);
                $this->roles->setGrants($role, $grants);

                if ($before != $grants) {
                    $this->audit->record('rbac', 'role_permissions_changed', subject: $role, before: ['permissions' => $before], after: ['permissions' => $grants]);
                }
            }
        });

        return new RoleResource($role->loadCount('users'));
    }

    public function destroy(Role $role): Response|JsonResponse
    {
        Gate::authorize('delete', $role);

        if (DB::table('user_roles')->where('role_id', $role->id)->exists()) {
            return Problem::response(409, 'role_in_use', 'Vai trò còn người dùng được gán. Hãy gỡ vai trò khỏi họ trước.');
        }

        DB::transaction(function () use ($role) {
            $before = $this->roles->grants($role);
            $role->delete();
            $this->audit->record('rbac', 'role_permissions_changed', subject: $role, before: ['permissions' => $before], after: ['permissions' => []]);
        });

        return response()->noContent();
    }

    /** Chặn (và ghi nhận) nỗ lực cấp quyền vượt quá quyền của chính người thao tác. */
    private function escalation($actor, array $grants, ?Role $role = null): ?JsonResponse
    {
        $exceeding = Catalog::uncovered($actor->permissionMap(), $grants);

        if ($exceeding === []) {
            return null;
        }

        $this->audit->record(
            'rbac', 'privilege_escalation_blocked',
            subject: $role,
            result: AuditLogger::DENIED,
            reason: 'permission_exceeds_privileges',
            metadata: ['exceeding' => array_slice($exceeding, 0, 50, true)],
        );

        return Problem::response(403, 'permission_exceeds_privileges', 'Không thể cấp quyền vượt quá quyền của chính bạn.');
    }

    private function uniqueKey(string $name): string
    {
        $base = 'custom-'.(Str::slug($name) ?: 'role');
        $key = $base;
        $i = 2;

        while (Role::where('key', $key)->exists()) {
            $key = $base.'-'.$i++;
        }

        return $key;
    }
}
