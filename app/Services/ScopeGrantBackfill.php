<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionScope;

/**
 * Phase B2 of the member-access plan (step 7): make legacy scope grants
 * explicit, per tenant role.
 *
 * For every role holding a legacy unsuffixed slug that now has scope
 * variants, add the variant(s) that legacy slug has always meant — normally
 * `X.view_all` plus `X.view_own`, and `X.view_own` only for `hrms.payroll.view`
 * whose legacy meaning was already narrower (declared in
 * `config/permissions.php` → `legacy_scope_aliases`).
 *
 * This is a 1:1 mapping, never a widening: the legacy slug keeps resolving
 * identically afterwards, but the explicit rows survive the alias cleanup
 * that later removes it, so a tenant admin can narrow a role without the
 * all-access the legacy slug granted unseen coming back. Custom UI roles are
 * mapped on the same rule as everyone else.
 *
 * All methods assume the tenant connection is active (call them inside
 * `TenantDatabaseManager::using()`). Applying is idempotent.
 */
class ScopeGrantBackfill
{
    /**
     * legacy slug => explicit variant slugs to grant as its replacement.
     *
     * @return array<string, list<string>>
     */
    private static function replacementMap(): array
    {
        $map = [];

        foreach (config('permissions.scopes') as $domain => $verbs) {
            foreach ($verbs as $verb) {
                $legacy = "{$domain}.{$verb}";

                $targets = ["{$legacy}_".PermissionScope::legacyScope($legacy)];

                if (PermissionScope::legacyScope($legacy) === 'all') {
                    $targets[] = "{$legacy}_own";
                }

                $map[$legacy] = $targets;
            }
        }

        return $map;
    }

    /**
     * Every explicit grant the migration would add, as report rows.
     *
     * @return list<array{role_id: int, role: string, slug: string}>
     */
    public function plan(): array
    {
        $map = self::replacementMap();
        $plan = [];

        foreach (Role::with('permissions')->orderBy('id')->get() as $role) {
            $held = $role->permissions->pluck('slug')->all();
            $adds = [];

            foreach ($held as $heldSlug) {
                foreach ($map[$heldSlug] ?? [] as $variant) {
                    if (! in_array($variant, $held, true)) {
                        $adds[] = $variant;
                    }
                }
            }

            $adds = array_values(array_unique($adds));
            sort($adds);

            foreach ($adds as $slug) {
                $plan[] = ['role_id' => $role->id, 'role' => $role->name, 'slug' => $slug];
            }
        }

        return $plan;
    }

    /**
     * Apply a plan (from the same tenant) and report how many grants landed.
     */
    public function apply(array $plan): int
    {
        $applied = 0;

        foreach (collect($plan)->groupBy('role_id') as $roleId => $rows) {
            $role = Role::find($roleId);

            if ($role === null) {
                continue;
            }

            $slugs = array_values(array_unique($rows->pluck('slug')->all()));
            $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();

            $role->permissions()->syncWithoutDetaching($ids);
            $applied += count($slugs);
        }

        return $applied;
    }
}
