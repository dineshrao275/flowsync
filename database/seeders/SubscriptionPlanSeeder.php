<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the subscription_plans catalog from config/subscriptions.php.
 * Idempotent: `updateOrCreate` by slug so catalog tweaks refresh existing rows.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = config('subscriptions.plans', []);
        $count = 0;

        // Migrations that seed plans run before `product` exists on older databases.
        $hasProduct = Schema::connection((new SubscriptionPlan)->getConnectionName())->hasColumn('subscription_plans', 'product');

        foreach ($plans as $slug => $attributes) {
            if (! $hasProduct) {
                unset($attributes['product']);
            }

            SubscriptionPlan::updateOrCreate(
                ['slug' => $slug],
                array_merge($attributes, [
                    'currency' => $attributes['currency'] ?? config('subscriptions.currency', 'USD'),
                ])
            );
            $count++;
        }

        // Ensure exactly one default stays on (starter wins, then the first).
        SubscriptionPlan::query()->update(['is_default' => false]);
        $defaultSlug = array_key_exists('starter', $plans) ? 'starter' : array_key_first($plans);
        SubscriptionPlan::where('slug', $defaultSlug)->update(['is_default' => true]);

        Log::info('Subscription plans seeded.', ['plans' => $count]);
    }
}
