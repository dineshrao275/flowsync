<?php

namespace App\Http\Controllers;

use App\Events\TaskSynced;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskMoveController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly ActivityLogger $logger,
    ) {}

    public function move(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('move', $task);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'index' => ['nullable', 'integer', 'min:0'],
        ]);

        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;
        $moved = $this->service->move($task, $data['status_id'], $data['index'] ?? null);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.moved',
            data: [
                'from_status_id' => $oldStatusId,
                'from_status' => $oldStatus?->name,
                'to_status_id' => $moved->status_id,
                'to_status' => $moved->status?->name,
                'key' => $moved->key,
                'to_is_done' => $moved->status_id !== $oldStatusId && (bool) $moved->status?->is_done,
            ],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        broadcast(new TaskSynced($moved, 'moved'));

        return response()->json([
            'message' => 'Task moved.',
            'task' => $this->service->present($this->service->show($moved)),
        ]);
    }
}
