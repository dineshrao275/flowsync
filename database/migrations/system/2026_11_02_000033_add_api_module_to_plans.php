<?php

use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/** P2.7 — adds the `api` module to the plans that sell it; additive, never removes anything. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pro', 'business', 'enterprise', 'tms-professional', 'tms-enterprise'] as $slug) {
            $plan = SubscriptionPlan::where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }
            $limits = (array) $plan->limits;
            $modules = (array) ($limits['modules'] ?? []);
            if (! in_array('api', $modules, true)) {
                $limits['modules'] = [...$modules, 'api'];
                $plan->update(['limits' => $limits]);
            }
        }
    }

    public function down(): void {}
};
