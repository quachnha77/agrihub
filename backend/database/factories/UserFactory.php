<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'tenant_id' => Tenant::factory(),
            'is_platform_admin' => false,
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(['tenant_id' => null, 'is_platform_admin' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => User::STATUS_DISABLED]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
