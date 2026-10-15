<?php

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — backfill explicit subscription state for seeded/legacy tenants.
 *
 * `TenantLimits::effective()` intentionally returns *unlimited* when a tenant
 * has no `subscriptions` row (legacy-safe default). This means any tenant that
 * was seeded before Phase 14 (ProvisionTenantJob) runs without any limit
 * forever. This migration gives each of those tenants an explicit row so the
 * plan limits actually kick in.
 *
 * Strategy:
 *   - Tenants that already have a subscription row: skip (idempotent).
 *   - Tenants whose status is `trial`: give them a `trialing` subscription
 *     on the default plan, expiring at `tenants.trial_ends_at` (or +14 days
 *     from now if null).
 *   - All other tenants without a row: give them `canceled` on the default
 *     plan (they are legacy seeded data, not real paying customers; their
 *     limits_override can always be set by a super admin).
 *
 * This is a system-DB migration (no tenant connections touched here).
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return app(TenantDatabaseManager::class)->centralConnectionName();
    }

    public function up(): void
    {
        $defaultPlan = SubscriptionPlan::where('is_default', true)->first()
            ?? SubscriptionPlan::orderBy('sort_order')->first();

        if (! $defaultPlan) {
            // No plans seeded yet (running on a fresh DB before the plan seeder).
            // Nothing to backfill.
            return;
        }

        $now = Carbon::now();

        // Fetch tenants that have no subscriptions row (LEFT JOIN is safest
        // across both PG and sqlite).
        $orphaned = DB::connection($this->getConnection())
            ->table('tenants')
            ->leftJoin('subscriptions', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->whereNull('subscriptions.id')
            ->whereNull('tenants.deleted_at')
            ->select('tenants.id', 'tenants.status', 'tenants.trial_ends_at')
            ->get();

        foreach ($orphaned as $tenant) {
            $isTrial = $tenant->status === Tenant::STATUS_TRIAL;

            $trialEndsAt = $isTrial
                ? ($tenant->trial_ends_at ? Carbon::parse($tenant->trial_ends_at) : $now->copy()->addDays(14))
                : null;

            $status = $isTrial ? 'trialing' : 'canceled';
            $periodEnd = $isTrial ? $trialEndsAt : $now->copy()->subDay(); // canceled in the past

            $subId = DB::connection($this->getConnection())->table('subscriptions')->insertGetId([
                'tenant_id' => $tenant->id,
                'plan_id' => $defaultPlan->id,
                'status' => $status,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $trialEndsAt,
                'canceled_at' => $isTrial ? null : $now->copy()->subDay(),
                'auto_renew' => false,
                'seats' => $defaultPlan->limit('users') ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Back-link the convenience pointer on tenants.
            DB::connection($this->getConnection())
                ->table('tenants')
                ->where('id', $tenant->id)
                ->update(['subscription_id' => $subId]);

            // Record an audit event so the trail is not blank.
            DB::connection($this->getConnection())->table('subscription_events')->insert([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subId,
                'type' => $isTrial ? 'trial_started' : 'canceled',
                'from_plan_id' => null,
                'to_plan_id' => $defaultPlan->id,
                'actor_id' => null,
                'data' => json_encode(['note' => 'Phase 5 backfill migration']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Backfills are irreversible — removing them would silently re-grant
        // unlimited access to legacy tenants. The down() is intentionally a no-op.
    }
};
