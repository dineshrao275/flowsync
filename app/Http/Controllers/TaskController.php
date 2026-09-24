<?php

namespace App\Http\Controllers;

use App\Events\TaskSynced;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly ActivityLogger $logger,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $filters = $request->validate([
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'label_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'view' => ['nullable', 'in:board,list'],
            'sort_by' => ['nullable', 'in:position,created_at,due_date,title'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        $board = ($filters['view'] ?? 'board') === 'board';

        if ($board) {
            return response()->json([
                'board' => $this->service->board($project, $filters),
                'filters' => $this->filtersPayload($project),
                'my_role' => $project->memberRole($request->user())?->slug,
            ]);
        }

        $tasks = $this->service->list($project, $filters);

        return response()->json([
            'tasks' => collect($tasks->items())->map(fn (Task $task) => $this->service->present($task)),
            'filters' => $this->filtersPayload($project),
            'my_role' => $project->memberRole($request->user())?->slug,
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
            'labels' => ['nullable', 'array'],
            'labels.*' => ['integer'],
            'due_date' => ['nullable', 'date'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $task = $this->service->create($project, $data, $request->user());

        $this->notifications->taskAssigned($request->user(), $task);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.created',
            data: ['key' => $task->key, 'title' => $task->title],
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
            'labels' => ['nullable', 'array'],
            'labels.*' => ['integer'],
            'due_date' => ['nullable', 'date'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('assignee_id', $data)) {
            $this->authorize('assign', $task);
        }

        $oldAssigneeId = $task->assignee_id;
        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;
        $updated = $this->service->update($task, $data);

        if (array_key_exists('assignee_id', $data) && $updated->assignee_id !== $oldAssigneeId) {
            $this->notifications->taskAssigned($request->user(), $updated);
        }

        if (array_key_exists('status_id', $data) && $updated->status_id !== $oldStatusId) {
            $this->notifications->taskStatusChanged(
                $request->user(),
                $updated,
                $oldStatus?->name ?? 'Unknown',
                $updated->status?->name ?? 'Unknown',
            );
        }

        $changed = array_intersect(
            array_keys($data),
            ['title', 'description', 'status_id', 'priority_id', 'assignee_id', 'parent_id', 'due_date', 'estimate_minutes', 'labels'],
        );

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.updated',
            data: ['fields' => array_values($changed)],
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
        $priorityIds = $project->tasks()->whereNotNull('priority_id')->distinct()->pluck('priority_id');

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
        ];
    }
}
