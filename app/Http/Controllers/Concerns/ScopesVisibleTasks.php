<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait ScopesVisibleTasks
{
    /**
     * Tenant-scoped task query restricted to projects the user belongs to,
     * unless they hold the tenant-wide `workspaces.manage` permission (or are
     * a non-impersonating super admin, who sees tasks across all tenants).
     */
    protected function visibleTaskQuery(User $user): Builder
    {
        $query = Task::query()
            ->with(['project', 'workspace', 'status', 'priority', 'assignee', 'labels']);

        if (! $this->userManagesAllTasks($user)) {
            $this->constrainToMemberScope($query, $user);
        }

        return $query;
    }

    /**
     * Membership AND row scope, per project (R10).
     *
     * A project role that reads only `tasks.view_own` / `_assigned` sees just
     * those rows on its board; the multi-project reads (search, dashboard,
     * reports, analytics) must not widen that. Projects are grouped by the
     * widest scope the caller's role there qualifies for, and each group is
     * constrained by `TaskScope` — the same rule the board and TaskPolicy use.
     * A role with no `tasks.view*` grant contributes no projects at all.
     */
    private function constrainToMemberScope(Builder $query, User $user): void
    {
        $memberships = DB::table('project_members')
            ->where('user_id', $user->id)
            ->pluck('project_role_id', 'project_id');

        $roles = ProjectRole::whereIn('id', $memberships->filter()->unique()->all())->get()->keyBy('id');

        $byScope = ['all' => [], 'assigned' => [], 'own' => []];
        foreach ($memberships as $projectId => $roleId) {
            $role = $roles->get($roleId);
            $scope = $role ? TaskScope::widestFor($role, 'tasks.view') : null;

            if ($scope !== null) {
                $byScope[$scope][] = (int) $projectId;
            }
        }

        $query->where(function (Builder $outer) use ($byScope, $user): void {
            $any = false;

            foreach ($byScope as $scope => $projectIds) {
                if ($projectIds === []) {
                    continue;
                }

                $any = true;
                $outer->orWhere(function (Builder $group) use ($projectIds, $scope, $user): void {
                    $group->whereIn('tasks.project_id', $projectIds);
                    TaskScope::constrainQuery($group, $user, $scope);
                });
            }

            if (! $any) {
                $outer->whereRaw('1 = 0');
            }
        });
    }

    protected function userManagesAllTasks(User $user): bool
    {
        return ($user->is_super_admin && ! request()->session()->has('impersonate'))
            || $user->hasPermission('workspaces.manage');
    }

    protected function presentTask(Task $task): array
    {
        return array_merge(app(TaskService::class)->present($task), [
            'updated_at' => $task->updated_at?->toIso8601String(),
            'project' => [
                'id' => $task->project?->id,
                'name' => $task->project?->name,
                'key' => $task->project?->key,
            ],
            'workspace' => [
                'id' => $task->workspace?->id,
                'name' => $task->workspace?->name,
            ],
        ]);
    }
}
