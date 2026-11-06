<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Project;
use App\Models\User;
use App\Support\VisibleTasks;

/** Counts for one project, computed over the tasks the caller can see (so row scopes shrink the numbers, never widen them). */
final class ProjectSummaryTool extends AbstractTool
{
    public function name(): string
    {
        return 'project_summary';
    }

    public function description(): string
    {
        return 'Summarise a project: how many tasks are open, done and overdue, and the split by status.';
    }

    public function parameters(): array
    {
        return $this->schema(['project_id' => ['type' => 'integer', 'minimum' => 1]], ['project_id']);
    }

    public function product(): ?string
    {
        return 'tms';
    }

    public function permission(): ?string
    {
        return 'workspaces.view';
    }

    public function execute(User $user, array $args): ToolResult
    {
        $manages = $user->hasPermission('workspaces.manage');
        $project = Project::query()->with('workspace')
            ->when(! $manages, fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id)))
            ->find($args['project_id']);

        if ($project === null) {
            return ToolResult::error("No project {$args['project_id']} that you can access.");
        }

        $tasks = VisibleTasks::query($user, $manages)->where('tasks.project_id', $project->id)->whereNull('tasks.archived_at');
        // One grouped count, not a task load: a project can hold thousands of tasks.
        $counts = (clone $tasks)->toBase()->selectRaw('tasks.status_id, count(*) as total')->groupBy('tasks.status_id')->pluck('total', 'status_id');
        $byStatus = $project->statuses()->orderBy('position')->get()
            ->mapWithKeys(fn ($status) => [$status->name => (int) ($counts[$status->id] ?? 0)])
            ->filter()->all();
        $open = (clone $tasks)->whereNull('tasks.completed_at');

        $data = [
            'project' => $project->name,
            'total' => (clone $tasks)->count(),
            'open' => (clone $open)->count(),
            'done' => (clone $tasks)->whereNotNull('tasks.completed_at')->count(),
            'overdue' => (clone $open)->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', now()->toDateString())->count(),
            'by_status' => $byStatus,
        ];

        return new ToolResult($data, [[
            'type' => 'project',
            'title' => "{$project->key} · {$project->name}",
            'subtitle' => "{$data['open']} open · {$data['done']} done · {$data['overdue']} overdue",
            'badges' => [],
            'link' => AiLinks::project($project->id),
        ]]);
    }
}
