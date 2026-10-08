<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();
        $slug = Str::slug($name).'-'.Str::random(4);

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => fake()->catchPhrase(),
            'status' => Tenant::STATUS_ACTIVE,
            'provisioning_status' => Tenant::PROVISIONING_PROVISIONED,
            'trial_ends_at' => null,
            'limits_override' => null,
            'features_override' => null,
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
    }
}
