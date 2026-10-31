<?php

namespace App\Services\Catalog;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\Audit\AuditLogger;
use App\Support\Catalog\ProductType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tạo / sửa / xóa / sao chép sản phẩm. Quyền (Policy) và kiểm tra dữ liệu (Request) đã làm xong ở lớp trên; lớp này lo tính nhất quán:
 * mọi thay đổi nằm trong MỘT transaction, `type` và đơn vị cơ sở không đổi sau khi tạo, và thay đổi giá / hệ số / mã vạch được ghi audit
 * kèm giá trước và sau (giá là dữ liệu tài chính).
 */
class ProductService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  đã kiểm tra; các khóa `*_id` là uuid công khai */
    public function create(Branch $branch, array $data): Product
    {
        return DB::transaction(function () use ($branch, $data) {
            $type = $data['type'];

            $product = new Product(Arr::only($data, ['sku', 'name', 'description', 'status']));
            $product->type = $type;
            $product->branch_id = $branch->id;
            $product->base_unit_id = Unit::where('uuid', $data['base_unit_id'])->value('id');
            $product->category_id = $this->idOf(Category::class, $data['category_id'] ?? null);
            $product->brand_id = $this->idOf(Brand::class, $data['brand_id'] ?? null);
            $product->specs = ProductType::normalize($type, $data['specs'] ?? []);
            $product->track_batch = ProductType::requiresBatchTracking($type) || (bool) ($data['track_batch'] ?? false);
            $product->save();

            $this->syncUnits($product, $data['units']);

            return $this->fresh($product);
        });
    }

    /** @param  array<string, mixed>  $data  đã kiểm tra; chỉ các khóa được gửi mới đổi */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->fill(Arr::only($data, ['sku', 'name', 'description', 'status']));

            if (array_key_exists('category_id', $data)) {
                $product->category_id = $this->idOf(Category::class, $data['category_id']);
            }

            if (array_key_exists('brand_id', $data)) {
                $product->brand_id = $this->idOf(Brand::class, $data['brand_id']);
            }

            if (array_key_exists('specs', $data)) {
                $product->specs = ProductType::normalize($product->type, $data['specs'] ?? []);
            }

            if (array_key_exists('track_batch', $data)) {
                $product->track_batch = ProductType::requiresBatchTracking($product->type) || (bool) $data['track_batch'];
            }

            $product->save();

            if (array_key_exists('units', $data)) {
                $this->syncUnits($product, $data['units']);
            }

            return $this->fresh($product);
        });
    }

    /** Xóa mềm. Mã vạch được nhả ra để sản phẩm khác dùng lại; mã sản phẩm tự được nhả (chỉ mục chỉ tính sản phẩm chưa xóa). */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            ProductUnit::where('product_id', $product->id)->update(['barcode' => null]);
            $product->delete();
        });
    }

    /**
     * Sao chép sản phẩm sang chi nhánh khác (cùng mã, loại, thuộc tính, đơn vị, giá, mã vạch). Mã đã có ở chi nhánh đích thì bỏ qua.
     *
     * @param  Collection<int, Product>  $products  đã được lọc theo quyền xem của người sao chép
     * @return array{copied: Collection<int, Product>, skipped: list<array{sku: string, name: string, reason: string}>}
     */
    public function copy(Collection $products, Branch $target): array
    {
        return DB::transaction(function () use ($products, $target) {
            $existing = Product::where('branch_id', $target->id)->pluck('sku')->map(fn (string $sku) => mb_strtolower($sku))->flip();
            $copied = new Collection;
            $skipped = [];

            foreach ($products->load('units') as $source) {
                if ($existing->has(mb_strtolower($source->sku))) {
                    $skipped[] = ['sku' => $source->sku, 'name' => $source->name, 'reason' => 'sku_exists'];

                    continue;
                }

                $clone = new Product(Arr::only($source->getAttributes(), ['sku', 'name', 'description', 'status']));
                $clone->specs = $source->specs;
                $clone->track_batch = $source->track_batch;
                $clone->type = $source->type;
                $clone->branch_id = $target->id;
                $clone->base_unit_id = $source->base_unit_id;
                $clone->category_id = $source->category_id;
                $clone->brand_id = $source->brand_id;
                $clone->save();

                foreach ($source->units as $unit) {
                    $this->newUnit($clone, $unit->unit_id, $unit->only(['factor', 'price_cash', 'price_credit', 'barcode', 'sort_order']))->save();
                }

                $this->audit->record('product', 'copied', $clone, metadata: ['from_product' => $source->uuid, 'from_branch' => $source->branch_id]);
                $existing->put(mb_strtolower($source->sku), true);
                $copied->push($this->fresh($clone));
            }

            return ['copied' => $copied, 'skipped' => $skipped];
        });
    }

    /**
     * Đồng bộ danh sách đơn vị bán: cập nhật dòng đã có, thêm dòng mới, xóa dòng không còn. Đã được Request bảo đảm có đúng một dòng
     * đơn vị cơ sở (hệ số 1) và không trùng đơn vị / mã vạch.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncUnits(Product $product, array $rows): void
    {
        $before = $this->snapshot($product);
        $map = Unit::whereIn('uuid', array_column($rows, 'unit_id'))->pluck('id', 'uuid');
        $existing = ProductUnit::where('product_id', $product->id)->get()->keyBy('unit_id');

        // Nhả mã vạch trước để hoán đổi mã vạch giữa hai đơn vị trong cùng một lần sửa không vướng chỉ mục duy nhất.
        ProductUnit::where('product_id', $product->id)->update(['barcode' => null]);

        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $unitId = $map[$row['unit_id']];
            $unit = $existing->get($unitId) ?? $this->newUnit($product, $unitId, []);
            $unit->fill([
                'factor' => $row['factor'],
                'price_cash' => $row['price_cash'],
                'price_credit' => $row['price_credit'],
                'barcode' => ($row['barcode'] ?? '') === '' ? null : trim((string) $row['barcode']),
                'sort_order' => $position,
            ])->save();
            $keep[] = $unitId;
        }

        ProductUnit::where('product_id', $product->id)->whereNotIn('unit_id', $keep)->delete();

        $after = $this->snapshot($product);

        if ($before !== $after) {
            $this->audit->record('product', 'units_changed', $product, before: $before === [] ? null : ['units' => $before], after: ['units' => $after]);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function newUnit(Product $product, int $unitId, array $values): ProductUnit
    {
        $unit = new ProductUnit($values);
        $unit->product_id = $product->id;
        $unit->unit_id = $unitId;
        $unit->branch_id = $product->branch_id; // sao từ sản phẩm để chỉ mục duy nhất của mã vạch nằm gọn trong một bảng

        return $unit;
    }

    /** @return list<array<string, mixed>> các đơn vị bán dạng so sánh được, dùng cho audit */
    private function snapshot(Product $product): array
    {
        return ProductUnit::with('unit')->where('product_id', $product->id)->orderBy('factor')->orderBy('id')->get()
            ->map(fn (ProductUnit $u) => [
                'unit' => $u->unit->name,
                'factor' => rtrim(rtrim((string) $u->factor, '0'), '.'),
                'price_cash' => (string) $u->price_cash,
                'price_credit' => (string) $u->price_credit,
                'barcode' => $u->barcode,
            ])->all();
    }

    /** @param  class-string<Category|Brand>  $model */
    private function idOf(string $model, ?string $uuid): ?int
    {
        return $uuid === null ? null : $model::where('uuid', $uuid)->value('id');
    }

    private function fresh(Product $product): Product
    {
        return $product->refresh()->load(['branch', 'category', 'brand', 'baseUnit', 'units.unit']);
    }
}
