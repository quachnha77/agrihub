<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockDocument;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Billing\SubscriptionService;
use App\Services\Catalog\CatalogDefaults;
use App\Services\Catalog\ProductService;
use App\Services\Crm\CustomerService;
use App\Services\Crm\SupplierService;
use App\Services\Inventory\StockService;
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

    /**
     * Tồn đầu kỳ mẫu cho "Kho chính" của chi nhánh chính (chạy lại không nhập trùng) và mức tồn tối thiểu. Cố ý có đủ tình huống để thử cảnh báo:
     * thuốc có lô sắp hết hạn, một lô ĐÃ hết hạn, và vài mặt hàng dưới mức tối thiểu. Hạn dùng tính từ hôm nay nên luôn còn đúng tình huống.
     */
    private function seedStock(TenantContext $context, Tenant $tenant, Branch $branch): void
    {
        $context->runForTenant($tenant->id, function () use ($branch) {
            $warehouse = Warehouse::where('branch_id', $branch->id)->orderBy('id')->first();

            if (! $warehouse || StockDocument::where('warehouse_id', $warehouse->id)->exists()) {
                return;
            }

            $minimums = ['NPK-16' => 500, 'URE-01' => 200, 'TS-01' => 30, 'TC-01' => 5, 'LUA-ST25' => 100, 'DAY-01' => 20];
            foreach ($minimums as $sku => $min) {
                Product::where('branch_id', $branch->id)->where('sku', $sku)->whereNull('min_stock')->update(['min_stock' => $min]);
            }

            $line = function (string $sku, string $unit, float $quantity, int $cost, array $batch = []) use ($branch) {
                $product = Product::where('branch_id', $branch->id)->where('sku', $sku)->first();
                $unitId = Unit::where('name', $unit)->value('uuid');

                return $product && $unitId ? ['product_id' => $product->uuid, 'unit_id' => $unitId, 'quantity' => $quantity, 'unit_cost' => $cost] + $batch : null;
            };
            $day = fn (int $plus) => now()->addDays($plus)->toDateString();

            $lines = array_values(array_filter([
                $line('NPK-16', 'Bao', 40, 750000),
                $line('URE-01', 'Kg', 300, 12000),
                $line('TS-01', 'Hộp', 5, 470000, ['batch_no' => 'TS01-2501', 'expires_at' => $day(180), 'manufactured_at' => $day(-120)]),
                $line('TS-01', 'Gói', 20, 23000, ['batch_no' => 'TS01-2401', 'expires_at' => $day(25), 'manufactured_at' => $day(-700)]),
                $line('TC-01', 'Chai', 6, 90000, ['batch_no' => 'TC01-2301', 'expires_at' => $day(-10), 'manufactured_at' => $day(-800)]),
                $line('TC-01', 'Thùng', 1, 1100000, ['batch_no' => 'TC01-2502', 'expires_at' => $day(365), 'manufactured_at' => $day(-60)]),
                $line('LUA-ST25', 'Kg', 150, 25000, ['batch_no' => 'LUA-2501', 'expires_at' => $day(90), 'manufactured_at' => $day(-90)]),
                $line('DAY-01', 'Cái', 3, 10000),
            ]));

            if ($lines !== []) {
                app(StockService::class)->post($warehouse, StockDocument::OPENING, ['note' => 'Tồn đầu kỳ (dữ liệu mẫu)', 'lines' => $lines], null);
            }
        });
    }

    /** Nhóm khách, khách hàng và nhà cung cấp mẫu (chạy lại không tạo trùng). Số điện thoại và mã số thuế là DỮ LIỆU GIẢ. */
    private function seedCrm(TenantContext $context, Tenant $tenant, Branch $branch): void
    {
        $context->runForTenant($tenant->id, function () use ($branch) {
            $group = fn (string $name) => CustomerGroup::firstOrCreate(['name' => $name])->uuid;
            $agent = $group('Đại lý');
            $farmer = $group('Nông dân');
            $regular = $group('Khách quen');

            $customers = [
                ['type' => 'individual', 'name' => 'Nguyễn Văn Nông', 'phone' => '0911000001', 'group_id' => $farmer, 'credit_limit' => 5000000, 'credit_days' => 30, 'notes' => 'Trồng lúa 5 ha',
                    'addresses' => [['label' => 'Ruộng', 'address' => 'Ấp 3, xã Tân Lợi', 'district' => 'Huyện Thới Lai', 'province' => 'Cần Thơ']]],
                ['type' => 'individual', 'name' => 'Trần Thị Mận', 'phone' => '0911000002', 'group_id' => $regular, 'credit_limit' => 2000000, 'credit_days' => 15,
                    'addresses' => [['label' => 'Vườn', 'address' => 'Ấp 5, xã Phước Thới', 'province' => 'Cần Thơ']]],
                ['type' => 'agent', 'name' => 'Đại lý Phát Tài', 'phone' => '0911000003', 'group_id' => $agent, 'tax_code' => '0300000011', 'credit_limit' => 50000000, 'credit_days' => 45,
                    'addresses' => [['label' => 'Cửa hàng', 'address' => '25 Quốc lộ 91', 'district' => 'Quận Ô Môn', 'province' => 'Cần Thơ', 'is_default' => true]],
                    'contacts' => [['name' => 'Anh Tài', 'phone' => '0911000013', 'position' => 'Chủ đại lý']]],
                ['type' => 'cooperative', 'name' => 'HTX Nông nghiệp Thạnh Phú', 'phone' => '0911000004', 'group_id' => $agent, 'credit_limit' => 30000000, 'credit_days' => 60,
                    'contacts' => [['name' => 'Chị Lan', 'phone' => '0911000014', 'position' => 'Kế toán'], ['name' => 'Anh Hải', 'position' => 'Chủ nhiệm']]],
                ['type' => 'company', 'name' => 'Công ty TNHH Nông sản Xanh', 'phone' => '0911000005', 'tax_code' => '0300000022', 'credit_limit' => 0, 'notes' => 'Chỉ bán tiền mặt'],
                ['type' => 'individual', 'name' => 'Lê Văn Khách lẻ', 'status' => 'inactive', 'credit_limit' => 0],
            ];

            foreach ($customers as $sample) {
                if (Customer::where('name', $sample['name'])->exists()) {
                    continue;
                }

                app(CustomerService::class)->create($branch, ['branch_id' => $branch->uuid] + $sample);
            }

            $suppliers = [
                ['name' => 'Công ty Phân bón Miền Nam (mẫu)', 'tax_code' => '0300000101', 'phone' => '0281000001', 'address' => '1 Đường số 1, TP.HCM', 'payment_terms_days' => 30,
                    'contacts' => [['name' => 'Anh Hùng', 'phone' => '0911000101', 'email' => 'hung@mau.vn', 'position' => 'Kinh doanh']]],
                ['name' => 'Bayer Việt Nam (mẫu)', 'tax_code' => '0300000102', 'phone' => '0281000002', 'payment_terms_days' => 45],
                ['name' => 'Syngenta (mẫu)', 'tax_code' => '0300000103', 'payment_terms_days' => 30],
                ['name' => 'Đầu Trâu — Phân bón Bình Điền (mẫu)', 'tax_code' => '0300000104', 'payment_terms_days' => 15, 'status' => 'inactive'],
            ];

            foreach ($suppliers as $sample) {
                if (Supplier::where('name', $sample['name'])->exists()) {
                    continue;
                }

                app(SupplierService::class)->create($sample);
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
                $this->seedStock($context, $tenant, $branch);
                $this->seedCrm($context, $tenant, $branch);

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
