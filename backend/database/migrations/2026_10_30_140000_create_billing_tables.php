<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gói dịch vụ & thuê bao (xem docs/SUBSCRIPTION_BILLING.md).
 *
 * - plans: bảng dùng chung toàn hệ thống (không có tenant_id), do Platform Admin cấu hình.
 * - subscriptions: mỗi Tenant đúng một thuê bao; giá/giới hạn là bản chụp tại thời điểm cấu hình nên đổi giá gói
 *   không làm đổi giá của khách đang dùng.
 * - subscription_periods: sổ cái các kỳ (dùng thử, gia hạn, đổi gói); chỉ thêm, không sửa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('price_monthly'); // VND / người dùng / tháng
            $table->unsignedBigInteger('price_yearly'); // VND / người dùng / năm
            $table->unsignedInteger('max_branches')->nullable(); // null = không giới hạn
            $table->unsignedInteger('storage_mb')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('billing_cycle', 10); // monthly | yearly
            $table->unsignedInteger('seats'); // số người dùng được mua
            $table->unsignedBigInteger('unit_price'); // VND / người dùng / kỳ (bản chụp)
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('storage_mb')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('last_contacted_at')->nullable();
            $table->string('contact_note', 500)->nullable();
            $table->timestamps();
            $table->index('period_end');
        });

        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // trial | renewal | change
            $table->string('plan_key', 40);
            $table->string('plan_name');
            $table->string('billing_cycle', 10);
            $table->unsignedInteger('seats');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('amount'); // VND, 0 với dùng thử/đổi gói
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_ref', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable(); // user id của nhân viên ghi nhận (không FK: giữ nguyên khi user bị xóa)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'period_start']);
        });

        foreach (['subscriptions', 'subscription_periods'] as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }

        // Gói mặc định. GIÁ LÀ GIẢ ĐỊNH (chưa được chủ sản phẩm chốt): Super Admin chỉnh qua API /platform/plans.
        $now = now();
        DB::table('plans')->insert([
            [
                'uuid' => (string) Str::uuid(), 'key' => 'standard', 'name' => 'Tiêu chuẩn',
                'description' => 'Cửa hàng vừa và nhỏ.', 'price_monthly' => 150000, 'price_yearly' => 1500000,
                'max_branches' => 3, 'storage_mb' => 5120, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'uuid' => (string) Str::uuid(), 'key' => 'pro', 'name' => 'Nâng cao',
                'description' => 'Nhiều chi nhánh, dung lượng lớn.', 'price_monthly' => 250000, 'price_yearly' => 2500000,
                'max_branches' => 10, 'storage_mb' => 51200, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        foreach (['subscription_periods', 'subscriptions'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        }

        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
