<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\TaskScope;
use Illuminate\Database\Eloquent\Builder;

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
            TaskScope::constrainToMemberships($query, $user);
        }

        return $query;
    }

    /**
     * {@see visibleTaskQuery()} plus every relation {@see presentTask()} reads, so listing N
     * tasks costs one query per relation instead of up to three more per task (the dashboard
     * ran 59 queries, 24 of them lazy loads, before this).
     */
    protected function presentableTaskQuery(User $user): Builder
    {
        return $this->visibleTaskQuery($user)->with(['assignee.roles', 'issueType', 'version', 'components', 'watchers:users.id,name,email']);
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
