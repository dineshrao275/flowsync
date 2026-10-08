<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a user's effective task row-scope for a project-role permission.
 *
 * Phase C of the member-access plan: the project-role catalog carries
 * `tasks.*_own/_assigned/_all` variants (config/project_roles.php), and this
 * is the single place a task PER-ROW check (TaskPolicy) and a board/list
 * QUERY (TaskService) agree on what those grants mean for a concrete row:
 *
 *   own      assignee or reporter is the caller
 *   assigned the caller or one of their direct reports (ReportsTo)
 *   all      every task in the project
 *
 * The legacy unsuffixed slug means `_all` (PermissionScope::legacyScope), so
 * a project role holding `tasks.view` today reads exactly what it read
 * before the variants existed. A role with no grant for the base verb at all
 * answers null — the board gate 403s before a query runs, and the query
 * clamps to nothing as a belt against a future caller.
 */
final class TaskScope
{
    /**
     * Widest scope a role's grants qualify for, or null when the role holds
     * no grant for the base verb at all.
     */
    public static function widestFor(ProjectRole $role, string $base): ?string
    {
        foreach (['all', 'assigned', 'own'] as $scope) {
            if ($role->grants("{$base}_{$scope}")) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * The effective task scope for a board/list query: tenant admins bypass
     * to `_all` (the same rule TaskPolicy applies per row), everyone else is
     * bound by their project role.
     */
    public static function resolveQueryScope(Project $project, User $user, string $base): ?string
    {
        if ($user->hasPermission('workspaces.manage')) {
            return 'all';
        }

        $role = $project->memberRole($user);

        return $role === null ? null : self::widestFor($role, $base);
    }

    /**
     * Whether the row falls inside a scope for the caller.
     */
    public static function rowMatches(Task $task, User $user, string $scope): bool
    {
        if ($scope === 'all') {
            return true;
        }

        $ids = self::userIdsFor($user, $scope);

        return in_array($task->assignee_id, $ids, true)
            || in_array($task->reporter_id, $ids, true);
    }

    /**
     * Applies the scope to a task query. `null` clamps the query to nothing.
     */
    public static function constrainQuery(Builder $query, User $user, ?string $scope): Builder
    {
        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($scope === 'all') {
            return $query;
        }

        $ids = self::userIdsFor($user, $scope);

        return $query->where(function (Builder $q) use ($ids): void {
            $q->whereIn('tasks.assignee_id', $ids)
                ->orWhereIn('tasks.reporter_id', $ids);
        });
    }

    /**
     * User ids a scope covers: the caller, plus their direct reports for
     * `_assigned`. A caller with no employee record covers just themselves,
     * so `_assigned` degenerates to `_own` instead of erroring.
     *
     * @return list<int>
     */
    public static function userIdsFor(User $user, string $scope): array
    {
        $ids = [$user->id];

        if ($scope === 'assigned') {
            $ids = array_merge($ids, ReportsTo::idsFor($user));
        }

        return array_values(array_unique($ids));
    }
}
