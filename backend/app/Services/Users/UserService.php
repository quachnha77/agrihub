<?php

namespace App\Services\Users;

use App\Http\ApiProblemException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRevoker;
use App\Services\Billing\SubscriptionService;
use App\Services\Rbac\RoleAssignmentGuard;
use App\Support\Audit\AuditSuppression;
use App\Support\Auth\TempPassword;
use App\Support\Rbac\Catalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nghiệp vụ quản lý người dùng. Dùng cho cả quản trị Tenant (đã có Gate/Policy ở controller) lẫn Platform Admin.
 * Các hàm `assert*` kiểm tra quy tắc an toàn, ghi audit "denied" rồi ném ApiProblemException — gọi chúng
 * TRƯỚC khi mở transaction để dấu vết không bị rollback.
 */
class UserService
{
    public function __construct(
        private RoleAssignmentGuard $roles,
        private SessionRevoker $sessions,
        private AuditLogger $audit,
        private SubscriptionService $billing,
    ) {}

    /**
     * Tạo người dùng trong Tenant của $actor. Không truyền password thì sinh mật khẩu tạm.
     * Người dùng mới luôn phải đổi mật khẩu ở lần đăng nhập đầu.
     *
     * @param  array{name: string, email: string, phone?: ?string, password?: ?string, role_ids?: list<string>, branch_ids?: list<string>}  $data
     * @return array{0: User, 1: ?string} [user, mật khẩu tạm nếu được sinh]
     */
    public function create(User $actor, array $data): array
    {
        $this->billing->assertCanAddUser((int) $actor->tenant_id); // hết số người dùng của gói thì dừng sớm

        $roles = $this->roles->resolve($data['role_ids'] ?? []);
        $this->roles->assertAssignable($actor, $roles, new Collection);
        $branches = $this->resolveBranches($data['branch_ids'] ?? []);

        $temporary = null;
        $password = $data['password'] ?? null;
        if ($password === null) {
            $password = $temporary = TempPassword::generate();
        }

        $user = DB::transaction(function () use ($actor, $data, $password, $roles, $branches) {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $password]);
            $user->forceFill([
                'tenant_id' => $actor->tenant_id,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
            ])->save();

            if ($branches->isNotEmpty()) {
                $user->branches()->attach($branches->mapWithKeys(fn (Branch $b) => [$b->id => ['tenant_id' => $user->tenant_id]])->all());
            }

            if ($roles->isNotEmpty()) {
                $user->roles()->attach($roles->mapWithKeys(fn (Role $r) => [$r->id => ['tenant_id' => $user->tenant_id]])->all());
                $this->audit->record(
                    'rbac', 'user_roles_changed', subject: $user,
                    before: ['roles' => []], after: ['roles' => $roles->pluck('key')->sort()->values()->all()],
                );
            }

            return $user;
        });

        return [$user, $temporary];
    }

    /** Tên / số điện thoại. (Email là định danh đăng nhập nên không đổi qua API này.) */
    public function updateProfile(User $target, array $data): void
    {
        $target->fill(Arr::only($data, ['name', 'phone']))->save(); // Auditable tự ghi before/after
    }

    /** Khóa / mở khóa tài khoản. Khóa thì đăng xuất mọi thiết bị ngay. Idempotent. */
    public function applyStatus(User $actor, User $target, string $status, ?string $reason = null): void
    {
        $before = $target->status;

        if ($before === $status) {
            return;
        }

        if ($status === User::STATUS_ACTIVE && $target->tenant_id !== null) {
            $this->billing->assertCanAddUser((int) $target->tenant_id); // mở khóa cũng chiếm một suất người dùng
        }

        DB::transaction(function () use ($actor, $target, $status, $before, $reason) {
            AuditSuppression::run(fn () => $target->forceFill(['status' => $status])->save());

            $this->audit->record(
                'user', 'status_changed', subject: $target, actor: $actor, reason: $reason,
                before: ['status' => $before], after: ['status' => $status],
            );

            if ($status === User::STATUS_DISABLED) {
                $this->sessions->forUser($target->id);
            }
        });
    }

    /**
     * Cấp mật khẩu tạm mới: bắt đổi ở lần đăng nhập kế tiếp, mở khóa đăng nhập sai, đăng xuất mọi thiết bị.
     *
     * @return string mật khẩu tạm (chỉ hiển thị một lần, không lưu bản rõ)
     */
    public function applyPasswordReset(User $actor, User $target): string
    {
        $temporary = TempPassword::generate();

        DB::transaction(function () use ($actor, $target, $temporary) {
            AuditSuppression::run(fn () => $target->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'password_changed_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save());

            $this->audit->record(
                'user', 'password_reset', subject: $target, actor: $actor,
                metadata: ['by' => $actor->is_platform_admin ? 'platform' : 'tenant_admin'],
            );

            $this->sessions->forUser($target->id);
        });

        return $temporary;
    }

    /** Xóa mềm; đăng xuất mọi thiết bị. */
    public function delete(User $target): void
    {
        DB::transaction(function () use ($target) {
            $target->delete(); // Auditable ghi "deleted"
            $this->sessions->forUser($target->id);
        });
    }

    /**
     * Thay danh sách chi nhánh của user. Người thao tác có phạm vi "branch" chỉ gán được chi nhánh của mình và
     * không gỡ được chi nhánh nằm ngoài phạm vi của mình.
     *
     * @param  list<string>  $uuids
     */
    public function setBranches(User $actor, User $target, array $uuids): void
    {
        $branches = $this->resolveBranches($uuids);
        $ids = $branches->pluck('id')->all();

        if ($actor->permissionScope('tenant.user.update') === 'branch') {
            $mine = $actor->branchIds();
            $outside = array_diff($ids, $mine);

            if ($outside !== []) {
                $this->audit->record(
                    'rbac', 'privilege_escalation_blocked', subject: $target, result: AuditLogger::DENIED,
                    reason: 'branch_exceeds_privileges',
                    metadata: ['branches' => $branches->whereIn('id', $outside)->pluck('code')->values()->all()],
                );

                throw new ApiProblemException(403, 'branch_exceeds_privileges', 'Không thể gán chi nhánh nằm ngoài phạm vi của bạn.');
            }

            // Giữ nguyên các chi nhánh của user mà người thao tác không quản lý.
            $ids = array_values(array_unique([...$ids, ...array_diff($target->branches()->pluck('branches.id')->all(), $mine)]));
        }

        DB::transaction(function () use ($target, $ids) {
            $before = $target->branches()->pluck('branches.code')->sort()->values()->all();

            $target->branches()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $target->tenant_id]])->all());

            $after = $target->branches()->pluck('branches.code')->sort()->values()->all();

            if ($before !== $after) {
                $this->audit->record('user', 'branches_changed', subject: $target, before: ['branches' => $before], after: ['branches' => $after]);
            }
        });
    }

    // --- Quy tắc an toàn ------------------------------------------------------------------

    public function assertNotSelf(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new ApiProblemException(422, 'cannot_modify_self', 'Không thể thực hiện thao tác này trên chính tài khoản của bạn.');
        }
    }

    /** Không được thao tác trên người dùng có quyền cao hơn mình (vd. Admin không khóa được Owner). */
    public function assertCanManage(User $actor, User $target): void
    {
        if ($actor->is($target) || Catalog::covers($actor->permissionMap(), $target->permissionMap())) {
            return;
        }

        $this->audit->record(
            'rbac', 'privilege_escalation_blocked', subject: $target, result: AuditLogger::DENIED,
            reason: 'user_exceeds_privileges',
            metadata: ['exceeding' => array_slice(Catalog::uncovered($actor->permissionMap(), $target->permissionMap()), 0, 50, true)],
        );

        throw new ApiProblemException(403, 'user_exceeds_privileges', 'Không thể thao tác trên người dùng có quyền cao hơn bạn.');
    }

    /** Doanh nghiệp phải còn ít nhất một Chủ doanh nghiệp đang hoạt động. */
    public function assertNotLastOwner(User $target): void
    {
        $isOwner = $target->roles()->where('key', Role::OWNER)->exists();

        if ($isOwner && ! $this->otherActiveOwnerExists($target)) {
            $this->audit->record('rbac', 'user_change_blocked', subject: $target, result: AuditLogger::DENIED, reason: 'last_owner');

            throw new ApiProblemException(422, 'last_owner', 'Doanh nghiệp phải còn ít nhất một Chủ doanh nghiệp đang hoạt động.');
        }
    }

    public function otherActiveOwnerExists(User $user): bool
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

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, Branch>
     */
    private function resolveBranches(array $uuids): Collection
    {
        $uuids = array_values(array_unique($uuids));
        $branches = $uuids === [] ? new Collection : Branch::whereIn('uuid', $uuids)->get(); // TenantScope

        if ($branches->count() !== count($uuids)) {
            throw new ApiProblemException(422, 'validation_failed', 'Dữ liệu không hợp lệ.', [
                'errors' => ['branch_ids' => ['Có chi nhánh không tồn tại.']],
            ]);
        }

        return $branches;
    }
}
