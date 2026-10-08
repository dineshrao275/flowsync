<?php

use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Phase 15 — HRMS module entitlement (system database).
 *
 * This is a **plan-catalog** change, not a tenant-table change: it introduces
 * the `hrms.*` module keys so `TenantLimits::hasModule()` starts answering for
 * them, and adds the new `business` plan.
 *
 *  - `starter` gains no `hrms.*` key, so Starter tenants see no People section.
 *  - `pro` merges in the Plan B HRMS set. Existing pro tenants therefore gain
 *    it on their next request — no data movement, no status change.
 *  - `business` is seeded fresh. Existing tenants are deliberately NOT
 *    re-planned: moving a customer's plan silently would change what they are
 *    billed and hand them Plan C (expenses, performance, talent, engagement,
 *    analytics) for free. Upgrades are an explicit, audited action via
 *    `TenantSubscriptionController` (Phase 14), never a migration side effect.
 *  - `enterprise` merges in every `hrms.*` key.
 *
 * `limits.modules` is **merged**, never replaced, so a plan an operator has
 * customised in the database keeps its customised module list and simply gains
 * the new keys. `tenants.features_override` is left untouched: it refines a
 * plan decision, and re-applying it here could silently re-enable something a
 * super admin switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = config('subscriptions.plans', []);

        if ($catalog === []) {
            return;
        }

        $touched = [];

        foreach ($catalog as $slug => $attributes) {
            $limits = (array) ($attributes['limits'] ?? []);

            $plan = SubscriptionPlan::firstOrNew(['slug' => $slug]);

            // A brand-new plan takes the config verbatim. An existing one keeps
            // every numeric limit an operator has customised and only gains new
            // module keys — note this merges the `modules` *sub-key*, never the
            // whole `limits` object (replacing it would drop users/workspaces/
            // projects/tasks and silently hand every tenant "unlimited").
            if ($plan->exists) {
                $existing = (array) ($plan->limits ?? []);

                $limits = [
                    ...$existing,
                    ...$limits,
                    'modules' => $this->mergeModules(
                        (array) ($existing['modules'] ?? []),
                        (array) ($limits['modules'] ?? []),
                    ),
                ];
            }

            $plan->fill([
                ...$attributes,
                'limits' => $limits,
                'currency' => $attributes['currency'] ?? config('subscriptions.currency', 'USD'),
            ])->save();

            $touched[] = $slug;
        }

        Log::info('HRMS module entitlements merged into the plan catalog.', [
            'plans' => $touched,
        ]);
    }

    public function down(): void
    {
        // Module keys are additive, and which tenants depend on them is not
        // inferable from the catalog — so there is no safe automatic reverse.
        // Roll back with the usual forward-fix discipline: leave the keys in
        // place. `hrms.*` routes are still inert on tenants whose subscription
        // does not grant them, and a code rollback removes the routes.
    }

    /**
     * Union of two module lists, config order first then existing extras.
     *
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function mergeModules(array $existing, array $incoming): array
    {
        return array_values(array_unique([...$incoming, ...$existing]));
    }
};
