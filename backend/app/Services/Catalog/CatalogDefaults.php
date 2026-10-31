<?php

namespace App\Services\Catalog;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Dữ liệu danh mục tạo sẵn cho doanh nghiệp. Chạy ở chế độ nền tảng hoặc trong context của chính doanh nghiệp đó (RLS). */
class CatalogDefaults
{
    /**
     * Thêm các đơn vị tính thông dụng còn thiếu (so tên không phân biệt hoa/thường nên chạy lại không sinh trùng).
     * Bulk insert nên không sinh audit từng dòng.
     *
     * @return int số đơn vị đã thêm
     */
    public function seedUnits(Tenant $tenant): int
    {
        $have = DB::table('units')->where('tenant_id', $tenant->id)->pluck('name')->map(fn (string $name) => mb_strtolower($name))->flip();
        $now = now();

        $rows = collect((array) config('catalog.default_units'))
            ->reject(fn (string $name) => $have->has(mb_strtolower($name)))
            ->map(fn (string $name) => ['tenant_id' => $tenant->id, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])
            ->values()->all();

        if ($rows !== []) {
            DB::table('units')->insert($rows);
        }

        return count($rows);
    }
}
