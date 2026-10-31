<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Catalog\ProductType;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo (POST) và sửa (PATCH) sản phẩm. Quyền do ProductPolicy kiểm tra ở controller.
 *
 * - `type`, `branch_id`, `base_unit_id` chỉ gửi khi tạo (không đổi sau đó: tồn kho và chứng từ về sau dựa vào chúng).
 * - `units` là TOÀN BỘ các đơn vị bán, gồm đúng một dòng đơn vị cơ sở (hệ số 1); khi sửa, gửi `units` nghĩa là thay cả danh sách.
 * - `specs` (thông tin đặc thù theo loại hàng) cũng được ghi đè nguyên khối, nên khi gửi phải đủ các trường bắt buộc của loại đó.
 */
class ProductRequest extends FormRequest
{
    /**
     * Người không có quyền tạo/sửa sản phẩm nhận 403 ngay, trước cả bước kiểm tra dữ liệu (không lộ thông báo lỗi cho người không có quyền).
     * Phạm vi chi nhánh cụ thể do ProductPolicy kiểm tra ở controller.
     */
    public function authorize(): bool
    {
        return $this->user()?->can($this->creating() ? 'catalog.product.create' : 'catalog.product.update') ?? false;
    }

    private function creating(): bool
    {
        return $this->isMethod('POST');
    }

    private function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }

    private function type(): ?string
    {
        $type = $this->creating() ? $this->input('type') : $this->product()?->type;

        return is_string($type) && in_array($type, ProductType::keys(), true) ? $type : null;
    }

    /** Chi nhánh của sản phẩm (khi tạo: chi nhánh được chọn; khi sửa: chi nhánh hiện tại). */
    private function branchId(): ?int
    {
        return $this->creating()
            ? Branch::where('uuid', (string) $this->input('branch_id'))->value('id')
            : $this->product()?->branch_id;
    }

    /** Chi nhánh đích khi tạo (đã được kiểm tra tồn tại và đang hoạt động). */
    public function targetBranch(): Branch
    {
        return Branch::where('uuid', $this->validated('branch_id'))->firstOrFail();
    }

    public function rules(): array
    {
        $creating = $this->creating();
        $presence = $creating ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->tenantId();
        $inTenant = fn (string $table) => Rule::exists($table, 'uuid')->where('tenant_id', $tenantId);
        $max = (int) config('catalog.max_units_per_product');
        $type = $this->type();

        $rules = [
            'sku' => [$presence, 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $this->uniqueSku()],
            'name' => [$presence, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
            'status' => ['sometimes', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            'category_id' => ['nullable', 'uuid', $inTenant('categories')],
            'brand_id' => ['nullable', 'uuid', $inTenant('brands')],
            'track_batch' => ['sometimes', 'boolean', $this->batchTracking($type)],
            'units' => [$presence, 'array', 'min:1', 'max:'.$max],
            'units.*.unit_id' => ['required', 'uuid', 'distinct', $inTenant('units')],
            'units.*.factor' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:1000000000'],
            'units.*.price_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'units.*.price_credit' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'units.*.barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];

        if ($creating) {
            $rules['type'] = ['required', Rule::in(ProductType::keys())];
            $rules['base_unit_id'] = ['required', 'uuid', $inTenant('units')];
            $rules['branch_id'] = ['required', 'uuid', Rule::exists('branches', 'uuid')->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('deleted_at')];
        } else {
            $rules['type'] = ['prohibited'];
            $rules['base_unit_id'] = ['prohibited'];
            $rules['branch_id'] = ['prohibited'];
        }

        if ($type !== null && ($creating || $this->has('specs'))) {
            $rules = array_merge($rules, ProductType::rules($type));
        }

        return $rules;
    }

    /** Mã sản phẩm duy nhất trong chi nhánh, không phân biệt hoa/thường (sản phẩm đã xóa mềm không giữ mã). */
    private function uniqueSku(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branchId = $this->branchId();

            if ($branchId === null || ! is_string($value)) {
                return;
            }

            $taken = Product::where('branch_id', $branchId)
                ->whereRaw('lower(sku) = ?', [mb_strtolower($value)])
                ->when($this->product(), fn ($q, Product $own) => $q->where('id', '!=', $own->id))
                ->exists();

            if ($taken) {
                $fail('Mã sản phẩm này đã được dùng trong chi nhánh.');
            }
        };
    }

    /** Thuốc BVTV và hạt giống luôn theo dõi lô & hạn dùng: không cho tắt. */
    private function batchTracking(?string $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            if ($type !== null && ProductType::requiresBatchTracking($type) && filter_var($value, FILTER_VALIDATE_BOOLEAN) === false) {
                $fail('Loại hàng này bắt buộc theo dõi lô và hạn dùng.');
            }
        };
    }

    /** Các luật cần nhìn cả danh sách đơn vị: có đúng một đơn vị cơ sở (hệ số 1), mã vạch không trùng nhau hay với sản phẩm khác. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $units = $this->input('units');

            // Danh sách đã sai ở mức từng dòng thì báo các lỗi đó trước, chưa so sánh chéo.
            $broken = collect($validator->errors()->keys())->contains(fn (string $key) => str_starts_with($key, 'units'));

            if (! is_array($units) || $broken) {
                return;
            }

            $baseUnit = $this->creating() ? $this->input('base_unit_id') : $this->product()?->baseUnit?->uuid;
            $bases = array_values(array_filter($units, fn ($row) => is_array($row) && ($row['unit_id'] ?? null) === $baseUnit));

            if (count($bases) !== 1) {
                $validator->errors()->add('units', 'Danh sách đơn vị phải có đúng một dòng là đơn vị cơ sở của sản phẩm.');
            } elseif (abs((float) $bases[0]['factor'] - 1.0) > 1e-9) {
                $validator->errors()->add('units', 'Đơn vị cơ sở phải có hệ số quy đổi bằng 1.');
            }

            $this->checkBarcodes($validator, $units);
        }];
    }

    /** @param  array<int, mixed>  $units */
    private function checkBarcodes(Validator $validator, array $units): void
    {
        $seen = [];

        foreach ($units as $i => $row) {
            $code = is_array($row) ? ($row['barcode'] ?? null) : null;

            if (! is_string($code) || $code === '') {
                continue;
            }

            if (isset($seen[$code])) {
                $validator->errors()->add("units.{$i}.barcode", 'Mã vạch bị trùng trong cùng sản phẩm.');
            }

            $seen[$code] = true;
        }

        $branchId = $this->branchId();

        if ($branchId === null || $seen === []) {
            return;
        }

        $taken = ProductUnit::where('branch_id', $branchId)
            ->whereIn('barcode', array_keys($seen))
            ->when($this->product(), fn ($q, Product $own) => $q->where('product_id', '!=', $own->id))
            ->pluck('barcode')->all();

        foreach ($units as $i => $row) {
            if (is_array($row) && in_array($row['barcode'] ?? null, $taken, true)) {
                $validator->errors()->add("units.{$i}.barcode", 'Mã vạch này đã được dùng cho sản phẩm khác trong chi nhánh.');
            }
        }
    }

    public function attributes(): array
    {
        $names = [
            'sku' => 'mã sản phẩm', 'name' => 'tên sản phẩm', 'description' => 'mô tả', 'min_stock' => 'tồn tối thiểu', 'status' => 'trạng thái', 'type' => 'loại hàng',
            'category_id' => 'nhóm hàng', 'brand_id' => 'thương hiệu', 'base_unit_id' => 'đơn vị cơ sở', 'branch_id' => 'chi nhánh',
            'track_batch' => 'theo dõi lô và hạn dùng', 'units' => 'danh sách đơn vị',
            'units.*.unit_id' => 'đơn vị tính', 'units.*.factor' => 'hệ số quy đổi', 'units.*.price_cash' => 'giá tiền mặt',
            'units.*.price_credit' => 'giá công nợ', 'units.*.barcode' => 'mã vạch', 'specs' => 'thông tin đặc thù',
        ];

        foreach (ProductType::all()[$this->type() ?? ProductType::OTHER]['fields'] as $key => $field) {
            $names["specs.{$key}"] = mb_strtolower($field['label']);
        }

        return $names;
    }
}
