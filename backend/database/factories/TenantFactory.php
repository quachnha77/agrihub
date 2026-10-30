<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'status' => Tenant::STATUS_ACTIVE,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => Tenant::STATUS_SUSPENDED, 'suspended_at' => now()]);
    }
}
