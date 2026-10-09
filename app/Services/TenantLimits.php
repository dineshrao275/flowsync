<?php

namespace App\Services;

use App\Models\Hrms\Employee\Employee;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Plan enforcement (Phase 14).
 *
 * Effective limits = plan.limits merged over `tenants.limits_override`; a tenant
 * with NO subscription is treated as unlimited (keeps legacy tenants/demo data
 * running). When no tenant context is pinned (provisioning, seeders, unit-style
 * service tests) quota checks silently pass.
 */
class TenantLimits
{
    /**
     * Unit-of-work cache: fingerprint → merged limits array.
     *
     * The fingerprint includes tenant id, plan id, and the override payload so
     * an in-request `limits_override` write (tests, SA entitlement screen) is
     * not served a stale merge. The memo must never outlive its unit of work —
     * a plan edit has to be visible on the very next read — so
     * `AppServiceProvider` flushes it on `RequestHandled` and `JobProcessing`,
     * and the test harness flushes it per test.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $memo = [];

    /**
     * Flush the unit-of-work memo: test setUp/tearDown, plus the request and
     * queued-job boundaries registered in `AppServiceProvider`.
     */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    /**
     * Merged limits for a tenant (plan first, tenant override wins).
     *
     * Memoized for one request/queued job: the same tenant is only queried
     * once even when assertQuota() is called repeatedly (create task → user,
     * workspace, project, task each call this in the same request cycle).
     */
    public function effective(Tenant $tenant): array
    {
        $key = $this->memoKey($tenant);

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $limits = $this->planLimits($tenant);

        if (! empty($tenant->limits_override)) {
            $limits = array_merge($limits, $tenant->limits_override);
        }

        return self::$memo[$key] = $limits;
    }

    /**
     * Limits contributed by the tenant's subscriptions. One bundle (or one plan) is
     * used as-is; per-product plans are merged: modules are the union, and a numeric
     * key defined by several plans takes the most generous value (an explicit null =
     * unlimited wins) — a key a plan simply does not mention says nothing.
     *
     * @return array<string, mixed>
     */
    private function planLimits(Tenant $tenant): array
    {
        $plans = $this->liveSubscriptions($tenant)->map(fn (Subscription $s) => $s->plan)->filter()->values();

        if ($plans->count() <= 1) {
            return $plans->first()?->limits ?? [];
        }

        $merged = [];
        foreach ($plans as $plan) {
            foreach ($plan->limits ?? [] as $key => $value) {
                if ($key === 'modules') {
                    $merged['modules'] = array_values(array_unique([...($merged['modules'] ?? []), ...(array) $value]));
                } elseif (! array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                } elseif ($merged[$key] !== null) {
                    $merged[$key] = $value === null ? null : max($merged[$key], $value);
                }
            }
        }

        return $merged;
    }

    /** The tenant's not-ended subscriptions with their plans — loaded once per Tenant instance, like the relation used to be. */
    private function liveSubscriptions(Tenant $tenant): Collection
    {
        return $tenant->loadMissing('subscriptions.plan')->subscriptions
            ->filter(fn (Subscription $s) => $s->status !== Subscription::STATUS_ENDED)->values();
    }

    /** Products the tenant's subscriptions cover; a tenant with none is unlimited (both). @return list<string> */
    public function coveredProducts(Tenant $tenant): array
    {
        $subs = $this->liveSubscriptions($tenant);

        if ($subs->isEmpty()) {
            return [SubscriptionPlan::PRODUCT_TMS, SubscriptionPlan::PRODUCT_HRMS];
        }

        return $subs->flatMap(fn (Subscription $s) => $s->plan?->covers() ?? [])->unique()->values()->all();
    }

    /**
     * Whether a whole product (`tms` or `hrms`) is on for this tenant. A super admin's
     * `features_override.products.{product}` wins either way (off = hard stop, on = granted
     * without a plan); otherwise the tenant's subscriptions decide.
     */
    public function productEnabled(Tenant $tenant, string $product): bool
    {
        $override = data_get($tenant->features_override, "products.{$product}");

        if (is_bool($override)) {
            return $override;
        }

        return in_array($product, $this->coveredProducts($tenant), true);
    }

    /**
     * Which product a module key belongs to: `hrms.*` is HRMS; the task-side modules are
     * TMS; `branding` and `export.full` are platform-wide (null — the plan list decides).
     */
    public static function productOfModule(string $module): ?string
    {
        return match (true) {
            str_starts_with($module, 'hrms.') => SubscriptionPlan::PRODUCT_HRMS,
            in_array($module, ['branding', 'export.full'], true) => null,
            default => SubscriptionPlan::PRODUCT_TMS,
        };
    }

    /**
     * Single limit value; null = unlimited (not set by plan or override).
     */
    public function limit(Tenant $tenant, string $key): mixed
    {
        return data_get($this->effective($tenant), $key);
    }

    /**
     * Whether a feature module is enabled (absent from the merged limits = on).
     *
     * Three layers, in precedence order:
     *   1. `tenants.features_override.modules` — a per-tenant grant/switch
     *      written by a super admin. **Additive only** (D2.6): it can grant a
     *      module the plan omits, and disable one the plan grants, but it can
     *      never appear in the module list on its own — a tenant with no
     *      subscription and no override is still unlimited.
     *   2. `plans.limits.modules` via the subscription.
     *   3. No subscription at all ⇒ every module is on.
     */
    public function hasModule(Tenant $tenant, string $module): bool
    {
        // A switched-off product takes all of its modules with it, whatever else says.
        $product = self::productOfModule($module);
        if ($product !== null && ! $this->productEnabled($tenant, $product)) {
            return false;
        }

        $override = $this->moduleOverride($tenant, $module);

        if ($override !== null) {
            return $override;
        }

        $modules = $this->limit($tenant, 'modules');

        if ($modules === null) {
            return true;
        }

        return in_array($module, $modules, true);
    }

    /**
     * Why a module was denied: explicitly disabled by override vs absent from plan.
     */
    public function moduleDenialReason(Tenant $tenant, string $module): string
    {
        $override = $this->moduleOverride($tenant, $module);

        if ($override === false) {
            return 'module_disabled';
        }

        return 'module_not_on_plan';
    }

    /**
     * All modules currently enabled for a tenant, as flat dotted slugs.
     *
     * Used by `AuthController::payload` to populate `user.modules` and by the
     * super-admin entitlement screen. Ordering is the catalog order from
     * config so the UI tree does not reshuffle between requests.
     *
     * @return list<string>
     */
    public function enabledModules(Tenant $tenant): array
    {
        $modules = $this->limit($tenant, 'modules');
        $override = data_get($tenant->features_override, 'modules');
        $override = is_array($override) ? $override : [];

        $catalog = $this->allModules();

        // A tenant with no subscription (or a plan that pins no module list) is
        // on everything. The override then *refines* that base in both
        // directions: `false` switches a module off, `true` grants one. This is
        // the additive-only rule from D2.6 — the override can never be the
        // reason a tenant gains a module its plan did not already allow.
        $base = $modules === null ? $catalog : array_values((array) $modules);

        $enabled = array_values(array_filter(
            $base,
            fn (string $module): bool => ! $this->toggleOff($override, $module),
        ));

        foreach ($override as $module => $on) {
            if ($on && ! in_array($module, $enabled, true)) {
                $enabled[] = $module;
            }
        }

        return array_values(array_filter(
            $catalog,
            fn (string $module): bool => in_array($module, $enabled, true),
        ));
    }

    /**
     * Whether the whole HRMS surface is available: any `hrms.*` module enabled.
     *
     * The frontend uses this to decide whether to render the People section at
     * all; individual modules are still gated individually server-side.
     */
    public function isHrmsEnabled(Tenant $tenant): bool
    {
        return collect($this->enabledModules($tenant))
            ->contains(fn (string $module): bool => str_starts_with($module, 'hrms.'));
    }

    /**
     * The per-tenant module override, or null when the plan decides.
     *
     * @return array<string, bool>|null
     */
    private function moduleOverride(Tenant $tenant, string $module): ?bool
    {
        $override = data_get($tenant->features_override, 'modules');

        if (! is_array($override) || ! array_key_exists($module, $override)) {
            return null;
        }

        return (bool) $override[$module];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function toggleOff(array $override, string $module): bool
    {
        return array_key_exists($module, $override) && ! $override[$module];
    }

    /**
     * Every known module key, in catalog order.
     *
     * @return list<string>
     */
    private function allModules(): array
    {
        return array_values(config('subscriptions.modules', []));
    }

    /**
     * Enforce a numeric quota for the current tenant (guarded by TenantContext).
     *
     * @param  string  $resource  one of users|seats|workspaces|projects|tasks
     * @param  array{count?: int}  $context  optional precomputed count override
     *
     * @throws ValidationException 422 when the tenant is over its plan limit
     */
    public function assertQuota(string $resource, array $context = []): void
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null) {
            return;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return;
        }

        $limit = $this->limit($tenant, $resource);

        if ($limit === null) {
            return;
        }

        $count = $context['count'] ?? $this->currentCount($resource);

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'form' => sprintf(
                    'Your plan allows at most %d %s (%d in use). Upgrade your plan or contact support to add more.',
                    $limit,
                    $resource === 'seats' ? 'seats' : $resource,
                    $count,
                ),
            ]);
        }
    }

    /**
     * Current used count for a resource on the tenant (default) connection.
     */
    public function currentCount(string $resource): int
    {
        $resource = $resource === 'seats' ? 'users' : $resource;

        return match ($resource) {
            'users' => User::count(),
            'workspaces' => Workspace::count(),
            'projects' => Project::count(),
            'tasks' => Task::count(),
            // HRMS headcount (Phase 15). Without this the key returns 0 for an
            // unknown resource, so assertQuota('employees') would be a no-op that
            // looks like it is working.
            'employees' => Employee::count(),
            default => 0,
        };
    }

    private function memoKey(Tenant $tenant): string
    {
        return implode(':', [
            (string) $tenant->id,
            $this->liveSubscriptions($tenant)->map(fn (Subscription $s) => $s->product.':'.$s->plan_id)->implode(',').json_encode(data_get($tenant->features_override, 'products')),
            md5((string) json_encode($tenant->limits_override ?? [])),
        ]);
    }
}
