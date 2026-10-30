<?php

namespace App\Services\Rbac;

use App\Http\ApiProblemException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\Catalog;
use Illuminate\Support\Collection;

/** Quy tắc chống leo thang quyền khi gán/gỡ vai trò cho người dùng. */
class RoleAssignmentGuard
{
    public function __construct(private RoleService $roles, private AuditLogger $audit) {}

    /**
     * Tìm các vai trò của Tenant hiện tại theo uuid; thiếu bất kỳ vai trò nào thì báo lỗi validate.
     *
     * @param  list<string>  $uuids
     * @return Collection<int, Role>
     */
    public function resolve(array $uuids): Collection
    {
        $uuids = array_values(array_unique($uuids));
        $roles = $uuids === [] ? new Collection : Role::whereIn('uuid', $uuids)->get(); // TenantScope: chỉ vai trò của Tenant mình

        if ($roles->count() !== count($uuids)) {
            throw new ApiProblemException(422, 'validation_failed', 'Dữ liệu không hợp lệ.', [
                'errors' => ['role_ids' => ['Có vai trò không tồn tại.']],
            ]);
        }

        return $roles;
    }

    /**
     * Chỉ gán/gỡ được vai trò có quyền nằm trong phạm vi quyền của chính người thao tác
     * (nên chỉ người có đủ quyền như Owner mới gán/gỡ được Owner). Vi phạm được ghi audit "denied".
     *
     * @param  Collection<int, Role>  $requested  vai trò sẽ có sau thao tác
     * @param  Collection<int, Role>  $current  vai trò hiện có
     */
    public function assertAssignable(User $actor, Collection $requested, Collection $current, ?User $subject = null): void
    {
        $actorPermissions = $actor->permissionMap();

        foreach ($requested->merge($current)->unique('id') as $role) {
            $grants = $this->roles->grants($role);

            if (Catalog::covers($actorPermissions, $grants)) {
                continue;
            }

            $this->audit->record(
                'rbac', 'privilege_escalation_blocked',
                subject: $subject,
                result: AuditLogger::DENIED,
                reason: 'role_exceeds_privileges',
                metadata: [
                    'role' => $role->key,
                    'requested' => $requested->pluck('key')->all(),
                    'exceeding' => array_slice(Catalog::uncovered($actorPermissions, $grants), 0, 50, true),
                ],
            );

            throw new ApiProblemException(403, 'role_exceeds_privileges', 'Không thể gán hoặc gỡ vai trò có quyền vượt quá quyền của chính bạn.');
        }
    }
}
