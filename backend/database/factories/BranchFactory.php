<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            // Ở chế độ nền tảng, tenant_id phải được gán rõ ràng.
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CN-###')),
            'name' => 'Chi nhánh '.fake()->city(),
            'phone' => fake()->numerify('09########'),
            'address' => fake()->address(),
            'status' => 'active',
        ];
    }
}
