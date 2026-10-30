<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique()->after('id');
            // NULL chỉ dành cho Platform Admin (Super Admin); mọi user khác thuộc đúng 1 Tenant.
            $table->foreignId('tenant_id')->nullable()->after('uuid')->constrained('tenants')->restrictOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('tenant_id');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('status', 20)->default('active')->after('password'); // active | disabled
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_scope_check CHECK (
            (is_platform_admin AND tenant_id IS NULL) OR (NOT is_platform_admin AND tenant_id IS NOT NULL)
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tenant_scope_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['uuid', 'is_platform_admin', 'phone', 'status', 'failed_login_attempts', 'locked_until', 'last_login_at']);
        });
    }
};
