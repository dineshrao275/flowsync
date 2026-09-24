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
     */
    public function hasModule(Tenant $tenant, string $module): bool
    {
        $modules = $this->limit($tenant, 'modules');

        if ($modules === null) {
            return true;
        }

        return in_array($module, $modules, true);
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
                    'This %s has reached its plan limit (%d of %d). %s',
                    $resource === 'seats' ? 'tenant' : $resource,
                    $count,
                    $limit,
                    'Upgrade your plan or contact support to add more.'
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
