<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskWatcherController extends Controller
{
    public function __construct(
        private readonly TaskService $service,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $this->authorize('view', $task);

        return response()->json([
            'watchers' => $this->service->watchers($task),
        ]);
    }

    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $caller = $request->user();
        $targetUserId = $data['user_id'] ?? $caller->id;

        if ($targetUserId === $caller->id) {
            $this->authorize('view', $task);
            $targetUser = $caller;
        } else {
            $this->authorize('edit', $task);
            $targetUser = User::find($targetUserId);

            if ($targetUser === null) {
                throw ValidationException::withMessages([
                    'user_id' => 'The selected user does not exist in this tenant.',
                ]);
            }
        }

        if (! $project->isMember($targetUser)) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected user is not a member of this project.',
            ]);
        }

        $this->service->addWatcher($task, $targetUser);

        return response()->json([
            'message' => 'Watcher added.',
            'watchers' => $this->service->watchers($task),
        ]);
    }

    public function destroy(Request $request, Project $project, Task $task, ?User $user = null): JsonResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $caller = $request->user();

        if ($user === null || $user->id === $caller->id) {
            $this->authorize('view', $task);
            $targetUser = $caller;
        } else {
            $this->authorize('edit', $task);
            $targetUser = $user;
        }

        $this->service->removeWatcher($task, $targetUser);

        return response()->json([
            'message' => 'Watcher removed.',
        ]);
    }
}
