<?php

namespace Tests\Concerns;

use App\Models\Branch;
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
     * Tạo một Tenant có 1 chi nhánh và 1 user thuộc chi nhánh đó.
     *
     * @return array{0: Tenant, 1: User, 2: Branch}
     */
    protected function makeTenant(array $tenant = [], array $user = []): array
    {
        return $this->platform(function () use ($tenant, $user) {
            $t = Tenant::factory()->create($tenant);
            $b = Branch::factory()->create(['tenant_id' => $t->id]);
            $u = User::factory()->create(array_merge(['tenant_id' => $t->id], $user));
            $u->branches()->attach($b->id, ['tenant_id' => $t->id]);

            return [$t, $u, $b];
        });
    }

    protected function makePlatformAdmin(array $attributes = []): User
    {
        return $this->platform(fn () => User::factory()->platformAdmin()->create($attributes));
    }

    /** Header để request được coi là từ SPA được phép (Sanctum stateful => có session). */
    protected function spa(): static
    {
        // Cố định để test không phụ thuộc SANCTUM_STATEFUL_DOMAINS trong .env của từng máy/CI.
        config(['sanctum.stateful' => ['localhost']]);

        return $this->withHeaders(['Referer' => 'http://localhost']);
    }
}
