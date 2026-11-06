<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function task(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $activities = Activity::where('subject_type', Task::class)
            ->where('subject_id', $task->id)
            ->with('actor')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'activities' => $activities->map(fn (Activity $activity) => $this->present($activity)),
        ]);
    }

    public function project(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        // Two bounded reads merged in PHP. The old form plucked every task id of the project into
        // one IN list: 15k ids per call on a big project, and a hard failure past PDO's 65,535 binds.
        $limit = 200;
        $onTasks = Activity::where('subject_type', Task::class)
            ->whereIn('subject_id', $project->tasks()->select('tasks.id'))
            ->orderByDesc('id')->limit($limit)->get();
        $onProject = Activity::where('subject_type', Project::class)->where('subject_id', $project->id)
            ->orderByDesc('id')->limit($limit)->get();

        $activities = $onTasks->concat($onProject)->sortByDesc('id')->take($limit)->values()->load('actor');

        return response()->json([
            'activities' => $activities->map(fn (Activity $activity) => $this->present($activity)),
        ]);
    }

    private function present(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'action' => $activity->action,
            'data' => $activity->data,
            'created_at' => $activity->created_at?->toISOString(),
            'actor' => $activity->actor ? ['id' => $activity->actor->id, 'name' => $activity->actor->name] : null,
            'subject_type' => class_basename($activity->subject_type),
        ];
    }
}
