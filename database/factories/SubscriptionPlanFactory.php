<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        $name = fake()->word().' Plan';

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_default' => false,
            'billing_cycle' => 'monthly',
            'price_cents' => 2900,
            'currency' => 'usd',
            'trial_duration_days' => 14,
            'limits' => [
                'users' => 10,
                'workspaces' => 5,
                'projects' => 20,
                'tasks' => 1000,
                'modules' => ['time_tracking', 'reports'],
            ],
            'sort_order' => 10,
        ];
    }
}
