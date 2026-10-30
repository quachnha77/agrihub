<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Rbac\RoleProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Hai Tenant demo độc lập để thử cô lập dữ liệu và phân quyền. Mật khẩu "password" chỉ dùng cho dev. */
class DevSeeder extends Seeder
{
    /** local-part email => vai trò hệ thống */
    private const USERS = ['owner' => 'owner', 'admin' => 'admin', 'sales' => 'sales', 'kho' => 'warehouse'];

    /** Tenant demo cần thuê bao (như khi onboarding thật), nhưng còn hạn một năm để không bị chặn giữa lúc đang thử. */
    private function ensureSubscription(Tenant $tenant): void
    {
        if (Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        app(SubscriptionService::class)->startTrial($tenant, null)
            ->forceFill(['is_trial' => false, 'seats' => 20, 'period_end' => now()->addYear()])
            ->save();
    }

    public function run(TenantContext $context, RoleProvisioner $provisioner): void
    {
        $context->runAsPlatform(function () use ($provisioner) {
            foreach ([['Cửa hàng Nông Phát', 'nong-phat'], ['Đại lý Vật tư Xanh', 'vat-tu-xanh']] as $tenantIndex => [$name, $slug]) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);
                $provisioner->provision($tenant); // bổ sung vai trò còn thiếu cho Tenant đã tồn tại từ trước
                $this->ensureSubscription($tenant);
                $branch = Branch::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => 'CN-01'],
                    ['name' => 'Chi nhánh chính'],
                );

                foreach (array_keys(self::USERS) as $userIndex => $local) {
                    $roleKey = self::USERS[$local];
                    // Số điện thoại demo để thử đăng nhập bằng số: 0900000011 (Nông Phát/owner), 0900000012 (Nông Phát/admin), 0900000021 (Vật tư Xanh/owner)...
                    $phone = sprintf('09000000%d%d', $tenantIndex + 1, $userIndex + 1);
                    $email = "{$local}@{$slug}.test";
                    $user = User::where('email', $email)->first() ?? tap(
                        (new User(['name' => ucfirst($local)." {$name}", 'email' => $email, 'password' => 'password']))
                            ->forceFill(['tenant_id' => $tenant->id, 'email_verified_at' => now()])
                    )->save();
                    $user = User::where('email', $email)->first();

                    if ($user->phone !== $phone) {
                        $user->forceFill(['phone' => $phone])->save();
                    }

                    $user->branches()->syncWithoutDetaching([$branch->id => ['tenant_id' => $tenant->id]]);

                    $role = Role::where('tenant_id', $tenant->id)->where('key', $roleKey)->first();
                    $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $tenant->id]]);
                }
            }
        });
    }
}
