<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục sản phẩm (Giai đoạn 2, xem docs/DATABASE_DESIGN.md).
 *
 * - units / categories / brands: danh sách dùng chung TOÀN doanh nghiệp.
 * - products: thuộc về MỘT chi nhánh (mỗi chi nhánh có danh mục riêng — quyết định 2026-10-31); mã (sku) duy nhất trong chi nhánh.
 * - product_units: các đơn vị bán của sản phẩm, gồm đơn vị cơ sở (factor = 1) và các đơn vị quy đổi; mỗi đơn vị có giá tiền mặt và
 *   giá công nợ (tiền dùng NUMERIC, không float) và có thể có mã vạch riêng (duy nhất trong chi nhánh). `branch_id` được sao từ sản phẩm
 *   để chỉ mục duy nhất của mã vạch nằm gọn trong một bảng.
 * - Thông tin đặc thù theo loại hàng (số đăng ký thuốc BVTV, giống, ...) nằm trong cột JSONB `specs`, được kiểm tra theo định nghĩa
 *   ở App\Support\Catalog\ProductType.
 *
 * Mọi bảng đều có tenant_id và RLS (FORCE) như các bảng nghiệp vụ khác.
 */
return new class extends Migration
{
    private const TABLES = ['units', 'categories', 'brands', 'products', 'product_units'];

    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Tên duy nhất trong doanh nghiệp, không phân biệt hoa/thường ("Bao" và "bao" là một).
        foreach (['units', 'categories', 'brands'] as $list) {
            DB::statement("CREATE UNIQUE INDEX {$list}_tenant_name_unique ON {$list} (tenant_id, lower(name))");
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('type', 20); // fertilizer | pesticide | seed | other
            $table->string('sku', 50);
            $table->string('name');
            // Nhóm hàng / thương hiệu: xóa khi đang dùng bị API chặn; sản phẩm đã xóa mềm thì chỉ mất liên kết.
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('description', 2000)->nullable();
            $table->jsonb('specs')->default('{}');
            $table->boolean('track_batch')->default(false); // theo dõi lô & hạn dùng
            $table->string('status', 20)->default('active'); // active | inactive (ngừng kinh doanh)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'branch_id', 'name']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('fertilizer', 'pesticide', 'seed', 'other'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'inactive'))");
        // Thuốc BVTV và hạt giống luôn theo dõi lô & hạn dùng: ràng buộc ở mức database, không chỉ ở API.
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_track_batch_check CHECK (type NOT IN ('pesticide', 'seed') OR track_batch)");
        // Mã sản phẩm duy nhất trong chi nhánh (không phân biệt hoa/thường); sản phẩm đã xóa mềm không giữ mã.
        DB::statement('CREATE UNIQUE INDEX products_branch_sku_unique ON products (tenant_id, branch_id, lower(sku)) WHERE deleted_at IS NULL');

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 18, 6)->default(1); // bằng bao nhiêu đơn vị cơ sở (đơn vị cơ sở = 1)
            $table->decimal('price_cash', 14, 2)->default(0); // giá bán tiền mặt
            $table->decimal('price_credit', 14, 2)->default(0); // giá bán công nợ (ghi nợ)
            $table->string('barcode', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        DB::statement('ALTER TABLE product_units ADD CONSTRAINT product_units_factor_check CHECK (factor > 0)');
        DB::statement('ALTER TABLE product_units ADD CONSTRAINT product_units_prices_check CHECK (price_cash >= 0 AND price_credit >= 0)');
        DB::statement('CREATE UNIQUE INDEX product_units_branch_barcode_unique ON product_units (tenant_id, branch_id, barcode) WHERE barcode IS NOT NULL');

        foreach (self::TABLES as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }

        $this->seedDefaultUnits();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Doanh nghiệp đã có sẵn nhận bộ đơn vị tính thông dụng (doanh nghiệp mới nhận khi onboarding). */
    private function seedDefaultUnits(): void
    {
        DB::statement("select set_config('app.is_platform', 'on', false)");

        try {
            $now = now();

            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                DB::table('units')->insert(array_map(
                    fn (string $name) => ['tenant_id' => $tenantId, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                    config('catalog.default_units', ['Kg', 'Bao', 'Gói', 'Chai', 'Lọ', 'Lít', 'Can', 'Thùng', 'Hộp', 'Cái', 'Túi', 'Bịch']),
                ));
            }
        } finally {
            DB::statement("select set_config('app.is_platform', 'off', false)");
        }
    }
};
