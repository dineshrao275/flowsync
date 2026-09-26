<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantContext;
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
     * Merged limits for a tenant (plan first, tenant override wins).
     */
    public function effective(Tenant $tenant): array
    {
        $limits = [];

        if ($subscription = $tenant->subscription) {
            $limits = $subscription->plan?->limits ?? [];
        }

        if (! empty($tenant->limits_override)) {
            $limits = array_merge($limits, $tenant->limits_override);
        }

        return $limits;
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
            default => 0,
        };
    }
}
