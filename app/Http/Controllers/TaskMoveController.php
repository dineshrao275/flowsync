<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\Tasks\TaskChangeLogger;
use App\Services\TaskService;
use App\Services\Workflow\MoveApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskMoveController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
        private readonly TaskChangeLogger $changes,
        private readonly MoveApprovals $approvals,
    ) {}

    public function move(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('move', $task);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'index' => ['nullable', 'integer', 'min:0'],
        ]);

        $target = $project->statuses()->whereKey($data['status_id'])->first();
        if ($target !== null && $this->approvals->required($task, $target, $request->user())) {
            $pending = $this->approvals->request($task, $target, $data['index'] ?? null, $request->user());

            return response()->json(['message' => __('This move needs a workflow manager\'s approval — it has been requested.'), 'pending_approval' => true, 'request_id' => $pending->id], 202);
        }

        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;

        $isSameStatus = $oldStatusId === (int) $data['status_id'];
        if ($isSameStatus) {
            $currentTasks = $project->tasks()
                ->where('status_id', $oldStatusId)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');
            $currentIndex = $currentTasks->search($task->id);
            $targetIndex = isset($data['index']) ? (int) $data['index'] : $currentIndex;
            $clampedTargetIndex = max(0, min($targetIndex, max(0, $currentTasks->count() - 1)));
            if ($currentIndex !== false && $currentIndex === $clampedTargetIndex) {
                return response()->json([
                    'message' => __('Task position unchanged.'),
                    'task' => $this->service->present($this->service->show($task)),
                ]);
            }
        }

        $moved = $this->service->move($task, $data['status_id'], $data['index'] ?? null);

        $this->changes->moved($moved, $oldStatusId, $oldStatus, $request->user(), $request->ip());

        return response()->json([
            'message' => __('Task moved.'),
            'task' => $this->service->present($this->service->show($moved)),
        ]);
    }
}
