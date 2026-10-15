<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUserRouting>
 */
class TenantUserRoutingFactory extends Factory
{
    protected $model = TenantUserRouting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'user_id' => fake()->numberBetween(1, 1000),
            'name' => fake()->name(),
        ];
    }
}
