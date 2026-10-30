<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\SyncUserBranchesRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RoleAssignmentGuard;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Quản lý người dùng của Tenant. Dữ liệu được RLS giới hạn theo Tenant; phạm vi own/branch/tenant do UserPolicy xử lý;
 * chống leo thang quyền (không thao tác trên người có quyền cao hơn mình, không tự khóa, luôn còn Owner) do UserService.
 */
class UserController extends Controller
{
    public function __construct(
        private UserService $users,
        private RoleAssignmentGuard $roles,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $query = User::query()->with(['roles', 'branches'])->orderBy('name')->orderBy('id');

        match ($actor->permissionScope('tenant.user.view')) {
            'tenant' => null,
            'branch' => $query->whereIn('id', DB::table('user_branches')->whereIn('branch_id', $actor->branchIds())->select('user_id')),
            default => $query->whereKey($actor->getKey()),
        };

        if (! empty($validated['q'])) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $validated['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return UserResource::collection($query->paginate($validated['per_page'] ?? 20)->withQueryString());
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['roles', 'branches']));
    }

    /** Tạo người dùng. Bỏ trống password => sinh mật khẩu tạm, hiển thị một lần; người dùng phải đổi ở lần đăng nhập đầu. */
    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        [$user, $temporary] = $this->users->create($request->user(), $request->validated());

        $resource = new UserResource($user->load(['roles', 'branches']));
        if ($temporary !== null) {
            $resource->additional(['temporary_password' => $temporary]);
        }

        return $resource->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    /** Sửa tên/số điện thoại; khóa/mở khóa tài khoản qua `status`. Email là định danh nên không đổi ở đây. */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $actor = $request->user();
        $this->users->assertCanManage($actor, $user);

        $status = $request->validated('status');
        if ($status !== null && $status !== $user->status) {
            $this->users->assertNotSelf($actor, $user);
            if ($status === User::STATUS_DISABLED) {
                $this->users->assertNotLastOwner($user);
            }
        }

        DB::transaction(function () use ($request, $actor, $user, $status) {
            $this->users->updateProfile($user, $request->safe()->only(['name', 'phone']));

            if ($status !== null) {
                $this->users->applyStatus($actor, $user, $status, $request->validated('reason'));
            }
        });

        return new UserResource($user->refresh()->load(['roles', 'branches']));
    }

    /** Xóa mềm người dùng (dữ liệu và lịch sử vẫn được giữ). */
    public function destroy(Request $request, User $user): Response
    {
        Gate::authorize('delete', $user);

        $actor = $request->user();
        $this->users->assertNotSelf($actor, $user);
        $this->users->assertCanManage($actor, $user);
        $this->users->assertNotLastOwner($user);

        $this->users->delete($user);

        return response()->noContent();
    }

    /** Thay toàn bộ vai trò của user. */
    public function syncRoles(Request $request, User $user): UserResource
    {
        Gate::authorize('assignRoles', $user);

        $validated = $request->validate([
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['uuid', 'distinct'],
        ]);

        $actor = $request->user();
        $roles = $this->roles->resolve($validated['role_ids']);
        $current = $user->roles()->get();

        $this->roles->assertAssignable($actor, $roles, $current, $user);

        if (! $roles->contains(fn (Role $r) => $r->isOwner()) && $current->contains(fn (Role $r) => $r->isOwner())) {
            $this->users->assertNotLastOwner($user);
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

    /** Thay toàn bộ chi nhánh của user. */
    public function syncBranches(SyncUserBranchesRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $this->users->assertCanManage($request->user(), $user);
        $this->users->setBranches($request->user(), $user, $request->validated('branch_ids'));

        return new UserResource($user->refresh()->load(['roles', 'branches']));
    }

    /** Cấp mật khẩu tạm mới cho nhân viên (quên mật khẩu). Không dùng cho chính mình: hãy dùng đổi mật khẩu. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $actor = $request->user();
        $this->users->assertNotSelf($actor, $user);
        $this->users->assertCanManage($actor, $user);

        $temporary = $this->users->applyPasswordReset($actor, $user);

        return (new UserResource($user->refresh()->load(['roles', 'branches'])))
            ->additional(['temporary_password' => $temporary])
            ->response()
            ->header('Cache-Control', 'no-store');
    }
}
