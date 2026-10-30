<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleService;
use App\Support\Rbac\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Quản lý người dùng của Tenant: hiện mới có xem và gán vai trò (tạo/sửa user thuộc bước onboarding sau).
 * Dữ liệu được RLS giới hạn theo Tenant; phạm vi own/branch/tenant do UserPolicy xử lý.
 */
class UserController extends Controller
{
    public function __construct(private RoleService $roles, private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $query = User::query()->with('roles')->orderBy('name');

        match ($actor->permissionScope('tenant.user.view')) {
            'tenant' => null,
            'branch' => $query->whereIn('id', DB::table('user_branches')->whereIn('branch_id', $actor->branchIds())->select('user_id')),
            default => $query->whereKey($actor->getKey()),
        };

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return UserResource::collection($query->paginate($validated['per_page'] ?? 20));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['roles', 'branches']));
    }

    /** Thay toàn bộ vai trò của user. */
    public function syncRoles(Request $request, User $user): UserResource|JsonResponse
    {
        Gate::authorize('assignRoles', $user);

        $validated = $request->validate([
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['uuid', 'distinct'],
        ]);

        $roles = Role::whereIn('uuid', $validated['role_ids'])->get(); // TenantScope: chỉ vai trò của Tenant mình
        if ($roles->count() !== count($validated['role_ids'])) {
            return Problem::response(422, 'validation_failed', 'Có vai trò không tồn tại.', null, [
                'errors' => ['role_ids' => ['Có vai trò không tồn tại.']],
            ]);
        }

        // Chống leo thang quyền: chỉ gán được vai trò có quyền nằm trong phạm vi quyền của chính mình
        // (nên chỉ người có đủ quyền như Owner mới gán được Owner).
        $actorPermissions = $request->user()->permissionMap();
        $current = $user->roles()->get();
        foreach ($roles->merge($current)->unique('id') as $role) {
            if (! Catalog::covers($actorPermissions, $this->roles->grants($role))) {
                $this->audit->record(
                    'rbac', 'privilege_escalation_blocked',
                    subject: $user,
                    result: AuditLogger::DENIED,
                    reason: 'role_exceeds_privileges',
                    metadata: [
                        'role' => $role->key,
                        'requested' => $roles->pluck('key')->all(),
                        'exceeding' => array_slice(Catalog::uncovered($actorPermissions, $this->roles->grants($role)), 0, 50, true),
                    ],
                );

                return Problem::response(403, 'role_exceeds_privileges', 'Không thể gán hoặc gỡ vai trò có quyền vượt quá quyền của chính bạn.');
            }
        }

        $keepsOwner = $roles->contains(fn (Role $r) => $r->isOwner());
        if (! $keepsOwner && $current->contains(fn (Role $r) => $r->isOwner()) && ! $this->otherActiveOwnerExists($user)) {
            $this->audit->record('rbac', 'user_roles_change_blocked', subject: $user, result: AuditLogger::DENIED, reason: 'last_owner');

            return Problem::response(422, 'last_owner', 'Doanh nghiệp phải còn ít nhất một Chủ doanh nghiệp đang hoạt động.');
        }

        DB::transaction(function () use ($user, $roles, $current) {
            $user->roles()->detach();
            $user->roles()->attach($roles->mapWithKeys(fn (Role $r) => [$r->id => ['tenant_id' => $user->tenant_id]])->all());

            $this->audit->record(
                'rbac', 'user_roles_changed',
                subject: $user,
                before: ['roles' => $current->pluck('key')->sort()->values()->all()],
                after: ['roles' => $roles->pluck('key')->sort()->values()->all()],
            );
        });

        return new UserResource($user->load(['roles', 'branches']));
    }

    private function otherActiveOwnerExists(User $user): bool
    {
        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('r.key', Role::OWNER)
            ->where('ur.user_id', '!=', $user->id)
            ->where('u.status', User::STATUS_ACTIVE)
            ->whereNull('u.deleted_at')
            ->exists();
    }
}
