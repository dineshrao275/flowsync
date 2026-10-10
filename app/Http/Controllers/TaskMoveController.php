<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\Tasks\TaskChangeLogger;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskMoveController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly TaskChangeLogger $changes,
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

        $this->changes->moved($moved, $oldStatusId, $oldStatus, $request->user(), $request->ip());

        return response()->json([
            'message' => 'Task moved.',
            'task' => $this->service->present($this->service->show($moved)),
        ]);
    }
}
