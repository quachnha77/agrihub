<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;

trait CreatesTenants
{
    /** Chạy ở chế độ nền tảng (dựng dữ liệu test); context được khôi phục sau đó. */
    protected function platform(Closure $callback): mixed
    {
        return app(TenantContext::class)->runAsPlatform($callback);
    }

    /**
     * Tạo một Tenant (tự có đủ vai trò hệ thống) với 1 chi nhánh và 1 user thuộc chi nhánh đó.
     *
     * @param  list<string>  $roles  key vai trò hệ thống gán cho user (mặc định: owner)
     * @return array{0: Tenant, 1: User, 2: Branch}
     */
    protected function makeTenant(array $tenant = [], array $user = [], array $roles = [Role::OWNER]): array
    {
        return $this->platform(function () use ($tenant, $user, $roles) {
            $t = Tenant::factory()->create($tenant);
            $b = Branch::factory()->create(['tenant_id' => $t->id]);
            $u = User::factory()->create(array_merge(['tenant_id' => $t->id], $user));
            $u->branches()->attach($b->id, ['tenant_id' => $t->id]);
            $this->giveRoles($u, $roles);

            return [$t, $u, $b];
        });
    }

    /** Thêm một user nữa vào Tenant có sẵn. */
    protected function makeUserIn(Tenant $tenant, array $roles, ?Branch $branch = null, array $attributes = []): User
    {
        return $this->platform(function () use ($tenant, $roles, $branch, $attributes) {
            $u = User::factory()->create(array_merge(['tenant_id' => $tenant->id], $attributes));
            if ($branch !== null) {
                $u->branches()->attach($branch->id, ['tenant_id' => $tenant->id]);
            }
            $this->giveRoles($u, $roles);

            return $u;
        });
    }

    /** @param  list<string>  $roleKeys */
    protected function giveRoles(User $user, array $roleKeys): void
    {
        $this->platform(function () use ($user, $roleKeys) {
            $ids = Role::where('tenant_id', $user->tenant_id)->whereIn('key', $roleKeys)->pluck('id');
            $user->roles()->attach($ids->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $user->tenant_id]])->all());
            $user->flushPermissionCache();
        });
    }

    protected function roleOf(Tenant $tenant, string $key): Role
    {
        return $this->platform(fn () => Role::where('tenant_id', $tenant->id)->where('key', $key)->firstOrFail());
    }

    protected function makePlatformAdmin(array $attributes = []): User
    {
        return $this->platform(fn () => User::factory()->platformAdmin()->create($attributes));
    }

    /**
     * Bắt đầu như một trình duyệt mới: xóa phiên và các guard đã nhớ người dùng trước đó.
     * Cần khi một test chuyển giữa những người dùng KHÁC MẬT KHẨU (Sanctum gắn phiên với hash mật khẩu của từng người).
     */
    protected function freshBrowser(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** Header để request được coi là từ SPA được phép (Sanctum stateful => có session). */
    protected function spa(): static
    {
        // Cố định để test không phụ thuộc SANCTUM_STATEFUL_DOMAINS trong .env của từng máy/CI.
        config(['sanctum.stateful' => ['localhost']]);

        return $this->withHeaders(['Referer' => 'http://localhost']);
    }
}
