<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Services\Integrations\Git\GitTaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Development section of a task and the project-wide summary behind the board badges. Read-only. */
class TaskDevelopmentController extends Controller
{
    public function __construct(private readonly GitTaskPresenter $presenter) {}

    public function show(Request $request, Project $project, Task $task): JsonResponse
    {
        abort_unless($task->project_id === $project->id, 404);
        $this->authorize('view', $task);

        return response()->json(['development' => $this->presenter->forTask($task, $project, $request->user())]);
    }

    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->presenter->summary($project, $request->user()));
    }
}
