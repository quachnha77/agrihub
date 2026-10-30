<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> bảng thuộc Tenant cần RLS */
    private array $tenantTables = ['roles', 'role_permissions', 'user_roles'];

    public function up(): void
    {
        // Catalog quyền: toàn cục, do code định nghĩa (config/rbac.php), đồng bộ bằng `agrihub:sync-permissions`.
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('resource', 80);
            $table->string('action', 30);
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('key', 80);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('scope', 10); // own | branch | tenant
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->index(['tenant_id', 'permission_id']);
        });
        DB::statement("ALTER TABLE role_permissions ADD CONSTRAINT role_permissions_scope_check CHECK (scope IN ('own', 'branch', 'tenant'))");

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
            $table->index(['tenant_id', 'role_id']);
        });

        foreach ($this->tenantTables as $table) {
            $predicate = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint"
                ." OR current_setting('app.is_platform', true) = 'on')";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$predicate} WITH CHECK {$predicate}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tenantTables) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        }

        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
