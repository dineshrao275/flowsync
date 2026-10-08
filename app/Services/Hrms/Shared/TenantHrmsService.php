<?php

namespace App\Services\Hrms\Shared;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\TenantLimits;
use Illuminate\Support\Facades\Log;

/**
 * Shared/HRMS — per-tenant HRMS entitlement.
 *
 * Owns `tenants.features_override.modules`, the per-tenant layer that refines
 * (but never invents) the plan's module list:
 *
 *   - `true`  grants a module the plan omits.
 *   - `false` switches off one the plan grants.
 *
 * This is the one place allowed to write that column, so the audit trail and
 * the "additive only" rule cannot drift apart.
 */
class TenantHrmsService
{
    public function __construct(private readonly TenantLimits $limits) {}

    /**
     * Current entitlement for a tenant, including where the answer came from.
     *
     * @return array{enabled: bool, source: string, modules: array<string,bool>, effective_modules: list<string>, effective_hrms: list<string>}
     */
    public function status(Tenant $tenant): array
    {
        $override = $this->overrideMap($tenant);

        return [
            'enabled' => $this->limits->isHrmsEnabled($tenant),
            'source' => $this->source($tenant),
            'modules' => $override,
            'effective_modules' => $this->limits->enabledModules($tenant),
            'effective_hrms' => $this->hrmsModules($tenant),
        ];
    }

    /**
     * Replace the module checklist, keeping every non-HRMS override intact.
     *
     * @param  list<string>  $modules
     * @return array<string, bool>
     */
    public function setModules(Tenant $tenant, array $modules): array
    {
        $before = $this->overrideMap($tenant);

        $map = $this->withoutHrms($before);

        foreach ($modules as $module) {
            $map[$module] = true;
        }

        $this->write($tenant, $map, $before, 'modules');

        return $this->overrideMap($tenant->refresh());
    }

    /**
     * The single on/off switch: grant every `hrms.*` module, or revoke the
     * whole HRMS surface without disturbing a plan's non-HRMS modules.
     *
     * @return array<string, bool>
     */
    public function setEnabled(Tenant $tenant, bool $enabled): array
    {
        $before = $this->overrideMap($tenant);

        $map = $this->withoutHrms($before);

        if ($enabled) {
            foreach ($this->catalogHrms() as $module) {
                $map[$module] = true;
            }
        } else {
            foreach ($this->catalogHrms() as $module) {
                $map[$module] = false;
            }
        }

        $this->write($tenant, $map, $before, 'enabled');

        return $this->overrideMap($tenant->refresh());
    }

    /**
     * Persist the override, then record both the product audit row (who changed
     * which tenant) and the operational log line (what the request was).
     */
    private function write(Tenant $tenant, array $map, array $before, string $via): void
    {
        $features = $tenant->features_override ?? [];

        $features['modules'] = $map === [] ? null : $map;

        $tenant->update(['features_override' => $features]);

        $after = $this->overrideMap($tenant->refresh());

        AuditLog::create([
            'subject_type' => 'tenants',
            'subject_id' => $tenant->id,
            'action' => 'tenant.hrms_updated',
            'data' => ['via' => $via, 'before' => $before, 'after' => $after],
            'actor_id' => auth()->id(),
            'ip_address' => request()->ip(),
        ]);

        // Module names are catalog slugs, never sensitive — safe to log.
        Log::channel('hrms')->info('tenant.hrms.updated', [
            'tenant_id' => $tenant->id,
            'via' => $via,
            'granted' => array_keys(array_filter($after)),
            'revoked' => array_keys(array_filter($after, fn ($on) => $on === false)),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function overrideMap(Tenant $tenant): array
    {
        $modules = data_get($tenant->features_override, 'modules');

        if (! is_array($modules)) {
            return [];
        }

        return array_map(fn ($on) => (bool) $on, $modules);
    }

    /**
     * Which layer decides the answer, for the admin UI to explain itself.
     */
    private function source(Tenant $tenant): string
    {
        if ($this->overrideMap($tenant) !== []) {
            return 'override';
        }

        return $tenant->subscription ? 'plan' : 'none';
    }

    /**
     * @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    private function withoutHrms(array $map): array
    {
        return array_filter(
            $map,
            fn (string $module) => ! str_starts_with($module, 'hrms.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return list<string>
     */
    private function catalogHrms(): array
    {
        return array_values(array_filter(
            config('subscriptions.modules', []),
            fn (string $module) => str_starts_with($module, 'hrms.'),
        ));
    }

    /**
     * @return list<string>
     */
    private function hrmsModules(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->limits->enabledModules($tenant),
            fn (string $module) => str_starts_with($module, 'hrms.'),
        ));
    }
}
