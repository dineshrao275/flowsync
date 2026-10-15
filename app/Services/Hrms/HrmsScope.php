<?php

namespace App\Services\Hrms;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\ReportsTo;

/**
 * HRMS — which rows a viewer's view grant covers, one shared answer.
 *
 * The TMS twin of `App\Support\TaskScope`: the *set-level* half of the
 * policy question for every HRMS list and per-record read. A caller may
 * hold the same base view at one of three scopes (`hrms.leave.view_own`,
 * `_assigned`, `_all`), plus the independent `manage` all-or-nothing gate
 * which never enters the scope lattice. `_assigned` resolves through
 * `App\Services\ReportsTo` — the single definition of "your own rows plus
 * your direct reports' rows" shared with the task domain — so the HRMS
 * contexts cannot disagree with each other or with the TMS.
 *
 * Three hard rules every consumer relies on:
 *
 *  - the legacy unsuffixed slug answers `_all` (except payroll's `_own`
 *    alias), so a role that never listed a scope keeps reading the whole
 *    tenant — `PermissionScope` owns that reading;
 *  - `manage` alone still sees everything (it is not a scope, but it is
 *    the mutation gate, and it has always implied the read);
 *  - a login with no employment record reads an *empty* set, never a 403
 *    and never everyone — a service account must not inherit rows.
 */
final class HrmsScope
{
    /**
     * The widest view scope the user actually holds for `$base`, or null.
     *
     * Iterating widest-first makes the held grant the answer, and a wider
     * grant answering a narrower request is exactly what `granted()`
     * guarantees, so `view_own` → null-when-absent, `view_assigned`,
     * `view_all` (or a legacy slug that means all).
     */
    public static function widestView(User $user, string $base): ?string
    {
        foreach (['all', 'assigned', 'own'] as $scope) {
            if ($user->granted("{$base}.view_{$scope}")) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Whether the caller reads every row: a `_all` view (which the legacy
     * slug satisfies) or the manage gate.
     */
    public static function seesAll(User $user, string $base): bool
    {
        return self::widestView($user, $base) === 'all'
            || $user->hasPermission("{$base}.manage");
    }

    /**
     * Whether the caller may read at all — any view scope or manage.
     *
     * The `viewAny` answer: the list may be *entered* (serving whatever
     * rows the scope covers, which may be none).
     */
    public static function canRead(User $user, string $base): bool
    {
        return self::widestView($user, $base) !== null
            || $user->hasPermission("{$base}.manage");
    }

    /**
     * The employment record ids the caller's view covers — own, or own plus
     * direct reports for `_assigned`. Only meaningful for a non-see-all
     * caller; a `_all`/manage holder gets `[]` (the no-constraint meaning)
     * and should apply no `employee_id` filter at all.
     *
     * No employment record yields `[]` — an own row set with nothing in it,
     * which reads as an empty list, not a 403: a service account must not
     * inherit anyone else's rows.
     *
     * @return list<int>
     */
    public static function employeeIdsFor(User $user, string $base): array
    {
        if (self::seesAll($user, $base)) {
            return [];
        }

        $ownId = Employee::where('user_id', $user->id)->value('id');

        $ids = $ownId === null ? [] : [(int) $ownId];

        if (self::widestView($user, $base) === 'assigned') {
            $ids = array_merge(
                $ids,
                Employee::query()->whereIn('user_id', ReportsTo::idsFor($user))->pluck('id')->all(),
            );
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Whether a specific employment record falls inside the caller's view.
     *
     * The per-record policy answer. A see-all caller covers every row
     * (orphaned rows included, matching the old "view || manage" read);
     * everyone else covers exactly their `employeeIdsFor` set.
     */
    public static function coversEmployee(User $user, string $base, ?Employee $employee): bool
    {
        if (self::seesAll($user, $base)) {
            return true;
        }

        return $employee !== null
            && in_array($employee->id, self::employeeIdsFor($user, $base), true);
    }
}
