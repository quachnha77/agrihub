<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Unit;

/** Dữ liệu danh mục cho test: đơn vị, nhóm hàng, thương hiệu và nội dung (payload) tạo sản phẩm. */
trait MakesCatalog
{
    protected function makeUnit(Tenant $tenant, string $name): Unit
    {
        return $this->platform(function () use ($tenant, $name) {
            $unit = new Unit(['name' => $name]);
            $unit->tenant_id = $tenant->id;
            $unit->save();

            return $unit;
        });
    }

    protected function makeCategory(Tenant $tenant, string $name): Category
    {
        return $this->platform(function () use ($tenant, $name) {
            $category = new Category(['name' => $name]);
            $category->tenant_id = $tenant->id;
            $category->save();

            return $category;
        });
    }

    protected function makeBrand(Tenant $tenant, string $name): Brand
    {
        return $this->platform(function () use ($tenant, $name) {
            $brand = new Brand(['name' => $name]);
            $brand->tenant_id = $tenant->id;
            $brand->save();

            return $brand;
        });
    }

    protected function makeBranch(Tenant $tenant, array $attributes = []): Branch
    {
        return $this->platform(fn () => Branch::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes)));
    }

    /**
     * Nội dung tạo sản phẩm hợp lệ (loại "vật tư khác", một đơn vị cơ sở); `$extra` ghi đè từng khóa.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function productPayload(Branch $branch, Unit $base, array $extra = []): array
    {
        return array_merge([
            'branch_id' => $branch->uuid,
            'type' => 'other',
            'sku' => 'VT-001',
            'name' => 'Dây tưới nhỏ giọt',
            'base_unit_id' => $base->uuid,
            'units' => [['unit_id' => $base->uuid, 'factor' => 1, 'price_cash' => 10000, 'price_credit' => 11000]],
        ], $extra);
    }

    /** Nội dung tạo thuốc BVTV hợp lệ. */
    protected function pesticidePayload(Branch $branch, Unit $base, array $extra = []): array
    {
        return $this->productPayload($branch, $base, array_merge([
            'type' => 'pesticide',
            'sku' => 'T-001',
            'name' => 'Thuốc trừ sâu ABC',
            'specs' => ['registration_no' => '1234/CP-BVTV', 'active_ingredient' => 'Abamectin 1.8%', 'toxicity_group' => 'III', 'phi_days' => 7],
        ], $extra));
    }
}
