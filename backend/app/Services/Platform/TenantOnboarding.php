<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\TempPassword;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboarding một doanh nghiệp mới trong MỘT transaction: Tenant (kèm 7 vai trò hệ thống qua observer),
 * chi nhánh đầu tiên và Chủ doanh nghiệp (Owner). Lỗi ở bất kỳ bước nào thì không tạo gì cả.
 */
class TenantOnboarding
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    /**
     * @param  array{tenant: array<string, mixed>, owner: array<string, mixed>, branch?: ?array<string, mixed>}  $data  đã validate
     * @return array{tenant: Tenant, owner: User, branch: Branch, temporary_password: ?string}
     */
    public function onboard(User $admin, array $data): array
    {
        $temporary = null;
        $password = $data['owner']['password'] ?? null;
        if ($password === null) {
            $password = $temporary = TempPassword::generate();
        }

        $slug = $data['tenant']['slug'] ?? $this->uniqueSlug($data['tenant']['name']);
        $branchData = array_filter($data['branch'] ?? [], fn ($v) => $v !== null && $v !== '') + config('platform.default_branch');

        $result = $this->context->runAsPlatform(fn () => DB::transaction(function () use ($admin, $data, $slug, $branchData, $password) {
            $tenant = new Tenant(Arr::only($data['tenant'], ['name', 'phone', 'address', 'tax_code']) + ['slug' => $slug]);
            $tenant->save(); // observer tạo các vai trò hệ thống

            $branch = new Branch(Arr::only($branchData, ['code', 'name']));
            $branch->tenant_id = $tenant->id;
            $branch->save();

            $owner = new User([
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'phone' => $data['owner']['phone'] ?? null,
                'password' => $password,
            ]);
            $owner->forceFill(['tenant_id' => $tenant->id, 'status' => User::STATUS_ACTIVE, 'must_change_password' => true])->save();

            $owner->branches()->attach($branch->id, ['tenant_id' => $tenant->id]);
            $ownerRole = Role::where('tenant_id', $tenant->id)->where('key', Role::OWNER)->firstOrFail();
            $owner->roles()->attach($ownerRole->id, ['tenant_id' => $tenant->id]);

            $this->audit->record(
                'tenant', 'onboarded', subject: $tenant, actor: $admin,
                metadata: [
                    'slug' => $tenant->slug,
                    'owner' => $owner->email,
                    'branch' => $branch->code,
                    'temp_login_issued' => ! isset($data['owner']['password']), // (tên khóa tránh từ 'password' để không bị che)
                ],
            );

            return compact('tenant', 'owner', 'branch');
        }));

        return $result + ['temporary_password' => $temporary];
    }

    /** Tạo slug từ tên, tránh slug dành riêng và trùng (kể cả Tenant đã xóa mềm). */
    public function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'doanh-nghiep';
        $taken = fn (string $s) => in_array($s, config('platform.reserved_slugs'), true)
            || $this->context->runAsPlatform(fn () => Tenant::withTrashed()->where('slug', $s)->exists());

        $slug = $base;
        for ($i = 2; $taken($slug); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
