<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Catalog\CatalogDefaults;
use App\Services\Catalog\ProductService;
use App\Services\Rbac\RoleProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

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

    /**
     * Vài nhóm hàng, thương hiệu và sản phẩm mẫu cho chi nhánh chính (chạy lại không tạo trùng). Số đăng ký thuốc BVTV là DỮ LIỆU GIẢ
     * (tiền tố DEMO-), chỉ để thử giao diện, không phải số đăng ký thật.
     */
    private function seedCatalog(TenantContext $context, Tenant $tenant, Branch $branch): void
    {
        app(CatalogDefaults::class)->seedUnits($tenant);

        $context->runForTenant($tenant->id, function () use ($branch) {
            $category = fn (string $name, int $order) => Category::firstOrCreate(['name' => $name], ['sort_order' => $order]);
            $brand = fn (string $name) => Brand::firstOrCreate(['name' => $name]);
            $unit = fn (string $name) => Unit::where('name', $name)->firstOrFail();
            $row = fn (string $unit, string $factor, int $cash, int $credit, ?string $barcode = null) => ['unit_id' => Unit::where('name', $unit)->value('uuid'), 'factor' => $factor, 'price_cash' => $cash, 'price_credit' => $credit, 'barcode' => $barcode];

            $fertilizer = $category('Phân bón', 1)->uuid;
            $pesticide = $category('Thuốc bảo vệ thực vật', 2)->uuid;
            $seed = $category('Hạt giống', 3)->uuid;

            $samples = [
                ['type' => 'fertilizer', 'sku' => 'NPK-16', 'name' => 'Phân NPK 16-16-8', 'category_id' => $fertilizer, 'brand_id' => $brand('Đầu Trâu')->uuid, 'base' => 'Kg',
                    'specs' => ['composition' => 'N 16% - P2O5 16% - K2O 8%', 'origin' => 'Việt Nam'],
                    'units' => [$row('Kg', '1', 15500, 16000), $row('Bao', '50', 750000, 780000, '8930000000012')]],
                ['type' => 'fertilizer', 'sku' => 'URE-01', 'name' => 'Phân Urê', 'category_id' => $fertilizer, 'brand_id' => $brand('Phú Mỹ')->uuid, 'base' => 'Kg',
                    'specs' => ['composition' => 'N 46%'],
                    'units' => [$row('Kg', '1', 13000, 13500), $row('Bao', '50', 630000, 650000)]],
                ['type' => 'pesticide', 'sku' => 'TS-01', 'name' => 'Thuốc trừ sâu (mẫu) 25WG', 'category_id' => $pesticide, 'brand_id' => $brand('Syngenta')->uuid, 'base' => 'Gói',
                    'specs' => ['registration_no' => 'DEMO-0001', 'active_ingredient' => 'Thiamethoxam 25%', 'toxicity_group' => 'III', 'phi_days' => 7],
                    'units' => [$row('Gói', '1', 25000, 27000), $row('Hộp', '20', 470000, 500000)]],
                ['type' => 'pesticide', 'sku' => 'TC-01', 'name' => 'Thuốc trừ cỏ (mẫu) 480SL', 'category_id' => $pesticide, 'brand_id' => $brand('Bayer')->uuid, 'base' => 'Chai',
                    'specs' => ['registration_no' => 'DEMO-0002', 'active_ingredient' => 'Glyphosate 480g/L', 'toxicity_group' => 'IV', 'phi_days' => 14],
                    'units' => [$row('Chai', '1', 95000, 100000), $row('Thùng', '12', 1100000, 1150000)]],
                ['type' => 'seed', 'sku' => 'LUA-ST25', 'name' => 'Lúa giống ST25', 'category_id' => $seed, 'brand_id' => null, 'base' => 'Kg',
                    'specs' => ['variety' => 'ST25', 'germination_rate' => 85, 'origin' => 'Sóc Trăng'],
                    'units' => [$row('Kg', '1', 28000, 30000), $row('Bao', '10', 270000, 285000)]],
                ['type' => 'other', 'sku' => 'DAY-01', 'name' => 'Dây tưới nhỏ giọt', 'category_id' => null, 'brand_id' => null, 'base' => 'Cái',
                    'specs' => [], 'units' => [$row('Cái', '1', 12000, 13000)]],
            ];

            foreach ($samples as $sample) {
                if (Product::where('branch_id', $branch->id)->whereRaw('lower(sku) = ?', [mb_strtolower($sample['sku'])])->exists()) {
                    continue;
                }

                app(ProductService::class)->create($branch, ['base_unit_id' => $unit($sample['base'])->uuid] + Arr::except($sample, ['base']));
            }
        });
    }

    public function run(TenantContext $context, RoleProvisioner $provisioner): void
    {
        $context->runAsPlatform(function () use ($provisioner, $context) {
            foreach ([['Cửa hàng Nông Phát', 'nong-phat'], ['Đại lý Vật tư Xanh', 'vat-tu-xanh']] as $tenantIndex => [$name, $slug]) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);
                $provisioner->provision($tenant); // bổ sung vai trò còn thiếu cho Tenant đã tồn tại từ trước
                $this->ensureSubscription($tenant);
                $branch = Branch::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => 'CN-01'],
                    ['name' => 'Chi nhánh chính'],
                );

                $this->seedCatalog($context, $tenant, $branch);

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
