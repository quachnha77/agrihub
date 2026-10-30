<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Hai Tenant demo độc lập để thử cô lập dữ liệu. Mật khẩu "password" chỉ dùng cho dev. */
class DevSeeder extends Seeder
{
    public function run(TenantContext $context): void
    {
        $context->runAsPlatform(function () {
            foreach ([['Cửa hàng Nông Phát', 'nong-phat'], ['Đại lý Vật tư Xanh', 'vat-tu-xanh']] as [$name, $slug]) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);
                $branch = Branch::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => 'CN-01'],
                    ['name' => 'Chi nhánh chính'],
                );

                $owner = User::where('email', "owner@{$slug}.test")->first() ?? tap(
                    (new User(['name' => "Chủ {$name}", 'email' => "owner@{$slug}.test", 'password' => 'password']))
                        ->forceFill(['tenant_id' => $tenant->id, 'email_verified_at' => now()])
                )->save();

                $owner->branches()->syncWithoutDetaching([$branch->id => ['tenant_id' => $tenant->id]]);
            }
        });
    }
}
