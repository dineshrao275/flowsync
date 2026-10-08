<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Resolves a role's `permissions` selector list from `config/permissions.php`
 * against the permission catalog (see the "Default Roles" comment in that file).
 *
 * Selector grammar — deliberately tiny, four cases, no regex, no recursion:
 *
 *   '*'                every slug in the catalog
 *   'hrms.*'           every slug with the "hrms." prefix
 *   '!hrms.payroll.*'  remove an already-selected group (order does not matter)
 *   'hrms.view'        one exact slug
 *
 * An unknown exact slug resolves to nothing rather than throwing: the catalog
 * is the source of truth and a typo should silently grant nothing, not break
 * tenant provisioning. Prefix globs that match nothing are equally harmless.
 *
 * `admin` short-circuits on `'*'` before any list handling, so the common case
 * costs one comparison.
 */
final class PermissionSelector
{
    /**
     * Expand a selector list into concrete permission slugs, de-duplicated and
     * ordered by the catalog (not by the selector list) so a role's stored
     * permission order is stable regardless of how it was expressed.
     *
     * A bare string is accepted because the historical `admin` role is declared
     * as `'permissions' => '*'` rather than `['*']`.
     *
     * @param  array<int, string>|string  $selectors  e.g. ['hrms.*', '!hrms.payroll.*']
     * @param  list<string>  $catalog  every slug in config('permissions.permissions')
     * @return list<string>
     */
    public function resolve(array|string $selectors, array $catalog): array
    {
        if (! is_array($selectors)) {
            $selectors = [$selectors];
        }

        if (in_array('*', $selectors, true)) {
            return $this->unique($catalog);
        }

        $selected = [];

        foreach ($selectors as $selector) {
            if (str_starts_with($selector, '!')) {
                continue; // exclusions are applied after the inclusions
            }

            array_push($selected, ...$this->included($selector, $catalog));
        }

        $excluded = [];

        foreach ($selectors as $selector) {
            if (! str_starts_with($selector, '!')) {
                continue;
            }

            array_push($excluded, ...$this->included(ltrim($selector, '!'), $catalog));
        }

        $remaining = array_diff($this->unique($selected), $excluded);

        // Emit in catalog order, not selector order: the result is snapshotted
        // onto role_user-adjacent pivot rows, and a role's stored permission
        // order must not depend on how the selector list happens to be written.
        return array_values(array_filter(
            $catalog,
            fn (string $slug): bool => in_array($slug, $remaining, true),
        ));
    }

    /**
     * Slugs a single non-negated selector pulls in, in catalog order.
     *
     * @param  list<string>  $catalog
     * @return list<string>
     */
    private function included(string $selector, array $catalog): array
    {
        if (! str_ends_with($selector, '.*')) {
            return in_array($selector, $catalog, true) ? [$selector] : [];
        }

        $prefix = substr($selector, 0, -2);

        return array_values(array_filter(
            $catalog,
            fn (string $slug): bool => str_starts_with($slug, $prefix.'.'),
        ));
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function unique(array $slugs): array
    {
        return array_values(array_unique($slugs));
    }

    /**
     * Catalog slugs straight from config, in declaration order.
     *
     * @return list<string>
     */
    public function catalog(): array
    {
        return array_values(Arr::pluck(
            config('permissions.permissions', []),
            'slug',
        ));
    }
}
