<?php

namespace App\Http\Controllers;

use App\Events\TaskSynced;
use App\Models\IssueType;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\TaskService;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // Board/list rows carry the task-level read, not just project
        // sight: a role that may see the project but not its tasks gets
        // the same 403 here that show() answers per row. Scope-aware: a
        // `tasks.view_own` grant passes the gate and the board/list then
        // narrow to the caller's rows.
        $user = $request->user();
        TaskScope::assertCanRead($project, $user);

        $filters = $request->validate([
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'label_id' => ['nullable', 'integer'],
            'issue_type_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'epic_id' => ['nullable', 'integer'],
            'component_id' => ['nullable', 'integer'],
            'sprint' => ['nullable', 'regex:/^(active|none|\d+)$/'],
            'q' => ['nullable', 'string', 'max:255'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'view' => ['nullable', 'in:board,list'],
            'column_limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort_by' => ['nullable', 'in:position,created_at,due_date,title'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        $board = ($filters['view'] ?? 'board') === 'board';

        if ($board) {
            return response()->json([
                'board' => $this->service->board($project, $filters, $user),
                'filters' => $this->filtersPayload($project),
                'my_role' => $project->memberRole($user)?->slug,
            ]);
        }

        $tasks = $this->service->list($project, $filters, $user);

        return response()->json([
            'tasks' => collect($tasks->items())->map(fn (Task $task) => $this->service->present($task)),
            'filters' => $this->filtersPayload($project),
            'my_role' => $project->memberRole($user)?->slug,
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('createTask', $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'epic_id' => ['nullable', 'integer'],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'story_points' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'issue_type_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'components' => ['nullable', 'array'],
            'components.*' => ['integer'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $task = $this->service->create($project, $data, $request->user());

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.created',
            data: ['key' => $task->key, 'title' => $task->title, 'assignee_id' => $task->assignee_id],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        broadcast(new TaskSynced($task, 'created'));

        return response()->json([
            'message' => 'Task created.',
            'task' => $this->service->present($this->service->show($task)),
        ], 201);
    }

    public function show(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'task' => $this->service->present($this->service->show($task)),
        ]);
    }

    /**
     * Resolve one task by its project-scoped key (`PRJ-123`), for deep
     * links that land outside the current view's pool: the board only
     * carries top-level tasks, so a subtask link (or a filtered-out task)
     * would otherwise open the board with no drawer. Same policy and same
     * shape as show — the key is just another address for the row.
     */
    public function showByKey(Request $request, Project $project, string $key): JsonResponse
    {
        $task = $project->tasks()->where('key', $key)->firstOrFail();

        $this->authorize('view', $task);

        return response()->json([
            'task' => $this->service->present($this->service->show($task)),
        ]);
    }

    public function update(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('edit', $task);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'epic_id' => ['nullable', 'integer'],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'story_points' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'issue_type_id' => ['nullable', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'components' => ['nullable', 'array'],
            'components.*' => ['integer'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('assignee_id', $data)) {
            $this->authorize('assign', $task);
        }

        $oldAssigneeId = $task->assignee_id;
        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;
        $updated = $this->service->update($task, $data);

        $assigneeChanged = array_key_exists('assignee_id', $data) && $updated->assignee_id !== $oldAssigneeId;
        $statusChanged = array_key_exists('status_id', $data) && $updated->status_id !== $oldStatusId;

        $changed = array_intersect(
            array_keys($data),
            ['title', 'description', 'status_id', 'priority_id', 'assignee_id', 'parent_id', 'epic_id', 'due_date', 'estimate_minutes', 'labels', 'start_date', 'story_points', 'issue_type_id', 'version_id', 'components'],
        );

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.updated',
            data: [
                'fields' => array_values($changed),
                'key' => $updated->key,
                'to_is_done' => $updated->status_id !== $oldStatusId && (bool) $updated->status?->is_done,
                'assignee_changed' => $assigneeChanged,
                'status_changed' => $statusChanged,
                'from_status' => $oldStatus?->name ?? 'Unknown',
                'to_status' => $updated->status?->name ?? 'Unknown',
            ],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        broadcast(new TaskSynced($updated, 'updated'));

        return response()->json([
            'message' => 'Task updated.',
            'task' => $this->service->present($this->service->show($updated)),
        ]);
    }

    public function destroy(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $this->service->delete($task);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.deleted',
            data: ['key' => $task->key],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        broadcast(new TaskSynced($task, 'deleted'));

        return response()->json(['message' => 'Task deleted.']);
    }

    private function filtersPayload(Project $project): array
    {
        return [
            'statuses' => $project->statuses()->orderBy('position')->get()
                ->map(fn ($s) => $this->service->presentStatus($s)),
            'priorities' => Priority::orderBy('position')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'color' => $p->color,
                    'value' => $p->value,
                ]),
            'assignees' => $project->members()->orderBy('name')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'labels' => $project->workspace?->labels()->orderBy('name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color]),
            'issue_types' => IssueType::orderBy('position')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'icon' => $t->icon,
                    'color' => $t->color,
                    'is_subtask' => $t->is_subtask,
                    'hierarchy_level' => $t->level(),
                ]),
            'epics' => $project->tasks()->whereHas('issueType', fn ($q) => $q->where('hierarchy_level', 1))
                ->orderByDesc('id')->limit(200)->get(['id', 'key', 'title'])
                ->map(fn ($e) => ['id' => $e->id, 'key' => $e->key, 'title' => $e->title]),
            'versions' => $project->versions()->orderBy('name')->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'released' => $v->released,
                    'release_date' => $v->release_date?->toDateString(),
                ]),
            'components' => $project->components()->orderBy('name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                ]),
        ];
    }
}
