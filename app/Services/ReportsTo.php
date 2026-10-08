<?php

namespace App\Services;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * The single definition of "assigned to you" for Phase C's `_assigned` scope.
 *
 * One hop down the reporting line, shared by every HRMS policy and list query
 * (and the TMS policies that branch on the caller's team): your assigned rows
 * are YOUR rows plus the rows of your direct reports. `idsFor()` returns the
 * direct reports' user ids — the caller unions its own id where the domain's
 * ownership concept is "me". A login with no employee record reports to no
 * one and has no reports, which makes `_assigned` degenerate to own-only for
 * service accounts instead of erroring.
 *
 * Resolved once per unit of work (the TenantLimits memo pattern): the static
 * memo is flushed on RequestHandled / JobProcessing by AppServiceProvider and
 * per test by Tests\IsolatesDatabase, so a manager map edit is never held past
 * the request that made it.
 */
final class ReportsTo
{
    /** @var array<int, list<int>> cache of user id => direct-report user ids */
    private static array $memo = [];

    /**
     * The user ids of the employees who report directly to `$user`.
     *
     * @return list<int>
     */
    public static function idsFor(User $user): array
    {
        $id = $user->getKey();

        if (array_key_exists($id, self::$memo)) {
            return self::$memo[$id];
        }

        $employee = Employee::query()->where('user_id', $id)->first();

        if ($employee === null) {
            return self::$memo[$id] = [];
        }

        return self::$memo[$id] = Employee::query()
            ->where('manager_id', $employee->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();
    }

    /**
     * Clear the per-unit-of-work memo (never carry it across requests/jobs).
     */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }
}
