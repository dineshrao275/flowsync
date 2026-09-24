<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
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
            $query->whereHas('project', function (Builder $project) use ($user) {
                $project->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
            });
        }

        return $query;
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
