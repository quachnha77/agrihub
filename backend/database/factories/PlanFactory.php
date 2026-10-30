<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'key' => 'plan-'.fake()->unique()->bothify('????##'),
            'name' => fake()->words(2, true),
            'price_monthly' => 100000,
            'price_yearly' => 1000000,
            'max_branches' => 3,
            'storage_mb' => 1024,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
