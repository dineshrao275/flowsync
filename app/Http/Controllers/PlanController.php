<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\PriceChanges\PlanPriceChanges;
use App\Billing\Stripe\CatalogSync;
use App\Http\Controllers\Concerns\NormalizesBooleanInput;
use App\Http\Controllers\Concerns\ValidatesResourceLimits;
use App\Jobs\ArchiveStripePlan;
use App\Jobs\SyncPlanToStripe;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 14 — subscription-plan catalog (system DB). Reads are role-aware:
 * super admins get the full catalog, tenants the active plans; mutations are
 * super admin only.
 */
class PlanController extends Controller
{
    use NormalizesBooleanInput;
    use ValidatesResourceLimits;

    /**
     * Plan catalog. Super admins see every plan (admin page); any other
     * authenticated user sees the active plans only (tenant subscription page).
     */
    public function index(Request $request): JsonResponse
    {
        $isSuperAdmin = (bool) $request->user()?->is_super_admin;

        $query = SubscriptionPlan::orderBy('sort_order')->orderBy('id');

        if (! $isSuperAdmin) {
            $query->where('is_active', true);
        }

        $plans = $query->get();

        // Stripe ids and sync errors are the platform's business, not something a tenant needs to see.
        if (! $isSuperAdmin) {
            $plans->each->makeHidden(['stripe_product_id', 'stripe_price_id', 'stripe_price_key', 'stripe_sync_error', 'stripe_synced_at']);
        }

        return response()->json(['plans' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $plan = SubscriptionPlan::create([
            ...$data,
            'limits' => $this->cleanResourceLimits($data['limits'] ?? null),
            'currency' => $data['currency'] ?? config('subscriptions.currency', 'USD'),
        ]);

        $this->ensureSingleDefault($plan);
        $this->syncToStripe($plan);

        return response()->json(['plan' => $plan->fresh()], 201);
    }

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $data = $this->validateData($request, $plan);

        // The product is what subscriptions are keyed on; it cannot change under them.
        if (isset($data['product']) && $data['product'] !== ($plan->product ?? 'suite') && $plan->subscriptions()->exists()) {
            abort(422, __('A plan that tenants are subscribed to cannot change product.'));
        }

        $oldCents = (int) $plan->price_cents;
        $oldPriceId = $plan->stripe_price_id;

        // Raising a paying subscribers' price is deliberate: say who it reaches and make the admin confirm it.
        $newCents = array_key_exists('price_cents', $data) ? (int) ($data['price_cents'] ?? 0) : $oldCents;
        $changes = app(PlanPriceChanges::class);
        if ($newCents > $oldCents && $oldCents > 0 && ! $request->boolean('confirm_price_change')) {
            $affected = $changes->affectedCount($plan);
            if ($affected > 0) {
                return response()->json([
                    'message' => trans_choice('This price change reaches :count paying subscription.|This price change reaches :count paying subscriptions. Confirm to email them the new amount and the date it starts.', $affected, ['count' => $affected]),
                    'code' => 'price_change_confirmation_required',
                    'affected_subscriptions' => $affected,
                    'notice_days' => (int) config('billing.price_change_notice_days', 30),
                ], 409);
            }
        }

        $plan->update([
            ...$data,
            'limits' => $this->cleanResourceLimits($data['limits'] ?? null),
        ]);

        $this->ensureSingleDefault($plan);

        $plan->refresh();
        $changes->announce($plan, $oldCents, $oldPriceId);
        $this->syncToStripe($plan);

        return response()->json(['plan' => $plan->fresh()]);
    }

    /** Push this plan to Stripe now (the nightly job does it too): shows the result — or the error — on the plan. */
    public function sync(SubscriptionPlan $plan, CatalogSync $catalog): JsonResponse
    {
        abort_unless($catalog->enabled(), 422, __('Stripe is not configured on this installation.'));

        $catalog->syncPlan($plan); // a provider failure is answered as 503 and recorded on the plan

        return response()->json(['plan' => $plan->fresh(), 'message' => __('Plan synchronised with Stripe.')]);
    }

    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            abort(422, __('Cannot delete a plan that tenants are subscribed to.'));
        }

        if ($plan->is_default) {
            abort(422, __('Cannot delete the default plan.'));
        }

        $productId = $plan->stripe_product_id;
        $priceId = $plan->stripe_price_id;
        $plan->delete();

        if ($productId || $priceId) {
            ArchiveStripePlan::dispatch($productId, $priceId)->afterCommit();
        }

        return response()->json(['message' => __('Plan deleted.')]);
    }

    private function validateData(Request $request, ?SubscriptionPlan $plan = null): array
    {
        // Form payloads may carry "true"/"false" strings; a real boolean is
        // required so the model's boolean cast can't turn "false" into true.
        $this->normalizeRequestBooleans($request, ['is_active', 'is_default']);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('subscription_plans', 'slug')->ignore($plan),
            ],
            'product' => ['nullable', Rule::in(['suite', 'tms', 'hrms'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'annual'])],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'trial_duration_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'limits' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            ...$this->resourceLimitRules(),
        ]);
    }

    /** Queue the Stripe product/price sync (a no-op job while Stripe is not configured). */
    private function syncToStripe(SubscriptionPlan $plan): void
    {
        SyncPlanToStripe::dispatch($plan)->afterCommit();
    }

    private function ensureSingleDefault(SubscriptionPlan $plan): void
    {
        if ($plan->is_default) {
            SubscriptionPlan::where('id', '!=', $plan->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
