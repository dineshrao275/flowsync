<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'trial_ends_at' => null,
            'canceled_at' => null,
            'auto_renew' => true,
            'seats' => 10,
            'billing_provider' => 'stripe',
            'billing_reference' => 'sub_'.fake()->lexify('????????????'),
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn () => [
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(14),
            'current_period_end' => now()->addDays(14),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => Subscription::STATUS_CANCELED,
            'canceled_at' => now()->subDay(),
            'auto_renew' => false,
        ]);
    }
}
