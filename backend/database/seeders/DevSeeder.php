<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\RoleProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Hai Tenant demo độc lập để thử cô lập dữ liệu và phân quyền. Mật khẩu "password" chỉ dùng cho dev. */
class DevSeeder extends Seeder
{
    /** local-part email => vai trò hệ thống */
    private const USERS = ['owner' => 'owner', 'admin' => 'admin', 'sales' => 'sales', 'kho' => 'warehouse'];

    public function run(TenantContext $context, RoleProvisioner $provisioner): void
    {
        $context->runAsPlatform(function () use ($provisioner) {
            foreach ([['Cửa hàng Nông Phát', 'nong-phat'], ['Đại lý Vật tư Xanh', 'vat-tu-xanh']] as [$name, $slug]) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);
                $provisioner->provision($tenant); // bổ sung vai trò còn thiếu cho Tenant đã tồn tại từ trước
                $branch = Branch::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => 'CN-01'],
                    ['name' => 'Chi nhánh chính'],
                );

                foreach (self::USERS as $local => $roleKey) {
                    $email = "{$local}@{$slug}.test";
                    $user = User::where('email', $email)->first() ?? tap(
                        (new User(['name' => ucfirst($local)." {$name}", 'email' => $email, 'password' => 'password']))
                            ->forceFill(['tenant_id' => $tenant->id, 'email_verified_at' => now()])
                    )->save();
                    $user = User::where('email', $email)->first();

                    $user->branches()->syncWithoutDetaching([$branch->id => ['tenant_id' => $tenant->id]]);

                    $role = Role::where('tenant_id', $tenant->id)->where('key', $roleKey)->first();
                    $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $tenant->id]]);
                }
            }
        });
    }
}
