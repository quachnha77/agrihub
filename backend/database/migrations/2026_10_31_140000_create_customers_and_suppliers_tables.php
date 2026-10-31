<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khách hàng và nhà cung cấp (Giai đoạn 2, xem docs/DATABASE_DESIGN.md). Cả hai DÙNG CHUNG toàn doanh nghiệp (công nợ gộp một chỗ);
 * khách hàng có thêm "chi nhánh phụ trách" để phân quyền xem/sửa theo chi nhánh.
 *
 * - customer_groups: nhóm khách (Đại lý, Nông dân, Khách quen...), chỉ để phân loại và lọc.
 * - customers: hồ sơ; `credit_limit` (đồng, 0 = không cho ghi nợ) và `credit_days` (số ngày nợ tối đa, NULL = không quy định) là dữ liệu
 *   để bước Bán hàng/Công nợ áp dụng sau; tiền dùng NUMERIC.
 * - customer_addresses / customer_contacts: nhiều địa chỉ giao hàng (đúng một địa chỉ mặc định — ràng buộc ở database) và nhiều người liên hệ.
 * - suppliers / supplier_contacts: nhà cung cấp, điều khoản thanh toán `payment_terms_days`.
 * Mọi bảng có tenant_id và RLS (FORCE) như các bảng nghiệp vụ khác; mã (code) duy nhất trong doanh nghiệp, không phân biệt hoa/thường,
 * bản ghi đã xóa mềm không giữ mã.
 */
return new class extends Migration
{
    private const TABLES = ['customer_groups', 'suppliers', 'supplier_contacts', 'customers', 'customer_addresses', 'customer_contacts'];

    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX customer_groups_tenant_name_unique ON customer_groups (tenant_id, lower(name))');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('tax_code', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable(); // số ngày được chịu khi mua; NULL = không quy định
            $table->string('notes', 2000)->nullable();
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('CREATE UNIQUE INDEX suppliers_tenant_code_unique ON suppliers (tenant_id, lower(code)) WHERE deleted_at IS NULL');

        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('position', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); // chi nhánh phụ trách
            $table->string('code', 30);
            $table->string('type', 20); // individual | agent | cooperative | company
            $table->string('name');
            $table->string('phone', 30)->nullable(); // không bắt buộc duy nhất: người trong một nhà có thể dùng chung số
            $table->string('email')->nullable();
            $table->string('tax_code', 20)->nullable();
            $table->foreignId('group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->decimal('credit_limit', 14, 2)->default(0); // hạn mức công nợ (đồng); 0 = không cho ghi nợ
            $table->unsignedSmallInteger('credit_days')->nullable(); // số ngày nợ tối đa; NULL = không quy định
            $table->string('notes', 2000)->nullable();
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'phone']);
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (type IN ('individual', 'agent', 'cooperative', 'company'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit >= 0)');
        DB::statement('CREATE UNIQUE INDEX customers_tenant_code_unique ON customers (tenant_id, lower(code)) WHERE deleted_at IS NULL');

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 50)->nullable(); // Nhà, Vườn, Kho...
            $table->string('address');
            $table->string('ward', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        // Mỗi khách có tối đa MỘT địa chỉ mặc định: bảo đảm ở database, không chỉ ở API.
        DB::statement('CREATE UNIQUE INDEX customer_addresses_one_default ON customer_addresses (customer_id) WHERE is_default');

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (self::TABLES as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (['customer_contacts', 'customer_addresses', 'customers', 'supplier_contacts', 'suppliers', 'customer_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
