<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Testing\TestResponse;

/** Dữ liệu cho test kho: kho của chi nhánh, sản phẩm (có đơn vị quy đổi Bao = 50 Kg) và gọi API ghi phiếu kho. Cần dùng chung với MakesCatalog. */
trait MakesStock
{
    /** Kho chính của chi nhánh (được tạo tự động cùng chi nhánh). */
    protected function warehouseOf(Branch $branch): Warehouse
    {
        return $this->platform(fn () => Warehouse::where('branch_id', $branch->id)->orderBy('id')->firstOrFail());
    }

    protected function makeWarehouse(Branch $branch, string $code = 'KHO-02', string $name = 'Kho thuốc'): Warehouse
    {
        return $this->platform(function () use ($branch, $code, $name) {
            $warehouse = new Warehouse(['code' => $code, 'name' => $name]);
            $warehouse->tenant_id = $branch->tenant_id;
            $warehouse->branch_id = $branch->id;
            $warehouse->save();

            return $warehouse;
        });
    }

    /**
     * Tạo sản phẩm qua API bằng chủ doanh nghiệp. Hai đơn vị bán: $kg (cơ sở) và $bao (1 Bao = 50 Kg). `$pesticide` = thuốc BVTV (theo dõi lô).
     *
     * @return array<string, mixed> dữ liệu sản phẩm trả về
     */
    protected function makeProduct(object $owner, Branch $branch, Unit $kg, Unit $bao, string $sku = 'NPK-16', bool $pesticide = false, array $extra = []): array
    {
        $units = [
            ['unit_id' => $kg->uuid, 'factor' => 1, 'price_cash' => 15500, 'price_credit' => 16000],
            ['unit_id' => $bao->uuid, 'factor' => 50, 'price_cash' => 750000, 'price_credit' => 780000],
        ];
        $payload = $pesticide
            ? $this->pesticidePayload($branch, $kg, ['sku' => $sku, 'name' => 'Thuốc '.$sku, 'units' => $units] + $extra)
            : $this->productPayload($branch, $kg, ['sku' => $sku, 'name' => 'Phân '.$sku, 'units' => $units] + $extra);

        return $this->spa()->actingAs($owner)->postJson('/api/v1/products', $payload)->assertCreated()->json('data');
    }

    /**
     * Ghi một phiếu kho qua API.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra  ghi đè / bổ sung khóa của phiếu (reason, note...)
     */
    protected function postStock(object $user, Warehouse $warehouse, string $type, array $lines, array $extra = []): TestResponse
    {
        return $this->spa()->actingAs($user)->postJson('/api/v1/stock/documents', array_merge(['warehouse_id' => $warehouse->uuid, 'type' => $type, 'lines' => $lines], $extra));
    }
}
