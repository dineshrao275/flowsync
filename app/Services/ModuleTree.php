<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * Groups the flat module catalog into the display tree used by the admin
 * feature grid (Phase 15) and the HRMS overview.
 *
 * The catalog itself stays a flat list of dotted slugs because
 * `TenantLimits::hasModule()` and the `ensure_module` middleware compare it as
 * strings — grouping is a *presentation* concern, so it lives here rather than
 * leaking into entitlement logic or being re-derived (and re-labelled) in JS.
 *
 * `subscriptions.module_meta` owns the label + group for every key;
 * ModuleCatalogTest guarantees the two never drift.
 */
class ModuleTree
{
    /**
     * Modules grouped for display, preserving config order within each group.
     *
     * @return list<array{key: string, label: string, modules: list<array{key: string, label: string, depth: int}>}>
     */
    public function groups(): array
    {
        $meta = config('subscriptions.module_meta', []);

        $grouped = [];

        foreach (config('subscriptions.modules', []) as $module) {
            $entry = $meta[$module] ?? null;

            // An unlabelled module is a config bug; show it raw rather than
            // dropping it, so the grid never silently hides an entitlement.
            $groupKey = $entry['group'] ?? 'Other';
            $label = $entry['label'] ?? $module;

            $grouped[$groupKey]['key'] = $groupKey;
            $grouped[$groupKey]['label'] = $groupKey;
            $grouped[$groupKey]['modules'][] = [
                'key' => $module,
                'label' => $label,
                // How far below the platform namespace this key sits, so the
                // UI can indent `hrms.payroll.statutory` under `Payroll`.
                'depth' => substr_count($module, '.'),
            ];
        }

        return array_values($grouped);
    }

    /**
     * Human label for a module slug.
     */
    public function label(string $module): string
    {
        return Arr::get(config('subscriptions.module_meta', []), "$module.label", $module);
    }
}
