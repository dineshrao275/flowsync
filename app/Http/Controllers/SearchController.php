<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Http\Requests\SearchTasksRequest;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    use ScopesVisibleTasks;

    public function tasks(SearchTasksRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $user = $request->user();

        $query = $this->visibleTaskQuery($user)->withCount([
            'subtasks',
            'comments',
            'attachments',
            'openBlockers as open_blockers_count',
        ]);

        if (($filters['assignee'] ?? null) === 'me') {
            $query->where('tasks.assignee_id', $user->id);
        }

        if (! empty($filters['q'])) {
            $query->where(function (Builder $builder) use ($filters) {
                $builder->where('tasks.title', 'like', '%'.$filters['q'].'%')
                    ->orWhere('tasks.key', 'like', '%'.$filters['q'].'%')
                    ->orWhere('tasks.description', 'like', '%'.$filters['q'].'%');
            });
        }

        foreach (['status_id', 'priority_id', 'assignee_id', 'project_id', 'workspace_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where('tasks.'.$column, $filters[$column]);
            }
        }

        if (! empty($filters['label_id'])) {
            $query->whereHas('labels', fn (Builder $builder) => $builder->where('labels.id', $filters['label_id']));
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('tasks.due_date', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('tasks.due_date', '<=', $filters['due_to']);
        }

        $tasks = $query->orderByDesc('tasks.updated_at')
            ->orderByDesc('tasks.id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'tasks' => collect($tasks->items())
                ->map(fn (Task $task) => $this->presentTask($task))
                ->values(),
            'filters' => $this->filterOptions($user),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Distinct filter options across every task the user can see
     * (mirrors the board's filters payload, but tenant-wide).
     */
    protected function filterOptions(User $user): array
    {
        $base = $this->visibleTaskQuery($user);

        $statusIds = (clone $base)->whereNotNull('tasks.status_id')->distinct()->pluck('tasks.status_id');
        $priorityIds = (clone $base)->whereNotNull('tasks.priority_id')->distinct()->pluck('tasks.priority_id');
        $assigneeIds = (clone $base)->whereNotNull('tasks.assignee_id')->distinct()->pluck('tasks.assignee_id');
        $projectIds = (clone $base)->distinct()->pluck('tasks.project_id');
        $workspaceIds = (clone $base)->distinct()->pluck('tasks.workspace_id');
        $labelIds = DB::table('task_label')->whereIn('task_id', (clone $base)->select('tasks.id')->distinct())
            ->distinct()->pluck('label_id');

        return [
            'statuses' => TaskStatus::whereIn('id', $statusIds)->orderBy('position')->get()
                ->map(fn (TaskStatus $status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'slug' => $status->slug,
                    'color' => $status->color,
                    'position' => $status->position,
                ])->values(),
            'priorities' => Priority::whereIn('id', $priorityIds)->orderByDesc('value')->get()
                ->map(fn (Priority $priority) => [
                    'id' => $priority->id,
                    'name' => $priority->name,
                    'color' => $priority->color,
                ])->values(),
            'assignees' => User::whereIn('id', $assigneeIds)->orderBy('name')->get()
                ->map(fn (User $assignee) => [
                    'id' => $assignee->id,
                    'name' => $assignee->name,
                ])->values(),
            'projects' => Project::whereIn('id', $projectIds)->orderBy('name')->get()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'key' => $project->key,
                ])->values(),
            'workspaces' => Workspace::whereIn('id', $workspaceIds)->orderBy('name')->get()
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ])->values(),
            'labels' => Label::whereIn('id', $labelIds)->orderBy('name')->get()
                ->map(fn (Label $label) => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])->values(),
        ];
    }
}
