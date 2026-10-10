<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskBulkRequest;
use App\Http\Requests\TaskCloneRequest;
use App\Http\Requests\TaskMoveProjectRequest;
use App\Models\Project;
use App\Models\Task;
use App\Services\Tasks\TaskBulk;
use App\Services\Tasks\TaskCloner;
use App\Services\Tasks\TaskMover;
use App\Services\TaskService;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;

/** Clone, move-to-another-project and bulk edit/assign/transition of tasks (P4.6). */
class TaskBulkController extends Controller
{
    public function __construct(
        private readonly TaskBulk $bulk,
        private readonly TaskCloner $cloner,
        private readonly TaskMover $mover,
        private readonly TaskService $tasks,
    ) {}

    public function bulk(TaskBulkRequest $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        TaskScope::assertCanRead($project, $request->user());

        $result = $this->bulk->apply($project, $request->validated('task_ids'), $request->validated('action'), $request->fields(), $request->user(), $request->ip());

        return response()->json(['message' => count($result['updated']).' task(s) updated.'] + $result);
    }

    public function clone(TaskCloneRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $target = $this->target($request->validated('target_project_id'), $project);
        $this->authorize('createTask', $target);

        $result = $this->cloner->clone($task, $target, $request->validated(), $request->user(), $request->ip());

        return response()->json([
            'message' => 'Task cloned.',
            'task' => $this->tasks->present($this->tasks->show($result['task'])),
            'subtasks_cloned' => $result['subtasks'],
        ], 201);
    }

    public function moveProject(TaskMoveProjectRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);
        $target = $this->target($request->validated('target_project_id'), $project);
        $this->authorize('createTask', $target);

        $result = $this->mover->move($task, $target, $request->validated('status_map') ?? [], $request->user(), $request->ip());

        return response()->json([
            'message' => 'Task moved to '.$target->key.'.',
            'task' => $this->tasks->present($this->tasks->show($result['task'])),
            'moved' => $result['moved'],
            'dropped' => $result['dropped'],
        ]);
    }

    /** The target project, which the caller must be able to see (a hidden project answers like a missing one). */
    private function target(?int $id, Project $fallback): Project
    {
        $target = $id === null || $id === $fallback->id ? $fallback : Project::findOrFail($id);
        $this->authorize('view', $target);

        return $target;
    }
}
