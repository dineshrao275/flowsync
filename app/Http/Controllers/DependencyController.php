<?php

namespace App\Http\Controllers;

use App\Enums\TaskDependencyType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DependencyController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'blocked_by' => $task->dependencies()
                ->with('dependsOn:id,key,title,status_id,completed_at')
                ->orderBy('id')
                ->get()
                ->map(fn (TaskDependency $dependency) => $this->present($dependency)),
            'blocks' => TaskDependency::where('depends_on_task_id', $task->id)
                ->with('task:id,key,title,status_id,completed_at')
                ->orderBy('id')
                ->get()
                ->map(fn (TaskDependency $dependency) => $this->present($dependency, 'task')),
        ]);
    }

    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('edit', $task);

        $data = $request->validate([
            'depends_on_task_id' => ['required', 'integer'],
            'type' => ['required', 'in:'.implode(',', array_column(TaskDependencyType::cases(), 'value'))],
        ]);

        $blockerId = (int) $data['depends_on_task_id'];

        if ($blockerId === $task->id) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'A task cannot depend on itself.',
            ]);
        }

        $blocker = $task->project->tasks()->find($blockerId);

        if ($blocker === null) {
            throw ValidationException::withMessages([
                'depends_on_task_id' => 'The blocker task must belong to the same project.',
            ]);
        }

        $exists = TaskDependency::where('task_id', $task->id)
            ->where('depends_on_task_id', $blockerId)
            ->where('type', $data['type'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'form' => 'This dependency already exists.',
            ]);
        }

        if ($this->createsCycle($task, $blockerId)) {
            throw ValidationException::withMessages([
                'form' => 'Adding this dependency would create a cycle.',
            ]);
        }

        $dependency = TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $blockerId,
            'type' => $data['type'],
        ]);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.dependency_created',
            data: ['depends_on_task_id' => $blockerId, 'type' => $data['type']],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'message' => 'Dependency added.',
            'dependency' => $this->present($dependency->load('dependsOn:id,key,title,status_id,completed_at')),
        ], 201);
    }

    public function destroy(Request $request, Project $project, Task $task, TaskDependency $dependency): JsonResponse
    {
        if ($dependency->task_id !== $task->id) {
            abort(404);
        }

        $this->authorize('edit', $task);

        $wasBlocked = $task->openBlockers()->exists();
        $blocker = $dependency->dependsOn;

        $dependency->delete();

        if ($wasBlocked && ! $task->openBlockers()->exists()) {
            $this->notifications->taskUnblocked($request->user(), $task, $blocker);
        }

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.dependency_deleted',
            data: ['depends_on_task_id' => $dependency->depends_on_task_id],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => 'Dependency removed.']);
    }

    private function createsCycle(Task $task, int $blockerId): bool
    {
        $seen = [$blockerId];
        $queue = [$blockerId];

        while ($queue !== []) {
            $current = array_shift($queue);

            $next = TaskDependency::where('task_id', $current)->pluck('depends_on_task_id');

            foreach ($next as $candidate) {
                if ((int) $candidate === $task->id) {
                    return true;
                }

                if (! in_array((int) $candidate, $seen, true)) {
                    $seen[] = (int) $candidate;
                    $queue[] = (int) $candidate;
                }
            }
        }

        return false;
    }

    private function present(TaskDependency $dependency, string $taskKey = 'dependsOn'): array
    {
        $related = $dependency->$taskKey;

        return [
            'id' => $dependency->id,
            'type' => $dependency->type->value,
            'task_id' => $dependency->task_id,
            'depends_on_task_id' => $dependency->depends_on_task_id,
            'task' => $related ? [
                'id' => $related->id,
                'key' => $related->key,
                'title' => $related->title,
                'status_id' => $related->status_id,
                'completed_at' => $related->completed_at?->toISOString(),
            ] : null,
        ];
    }
}
