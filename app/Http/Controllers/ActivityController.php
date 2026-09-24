<?php

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

        $taskIds = $project->tasks()->pluck('id');

        $activities = Activity::where(function ($query) use ($project, $taskIds) {
            $query
                ->where(function ($q) use ($project) {
                    $q->where('subject_type', Project::class)->where('subject_id', $project->id);
                })
                ->orWhere(function ($q) use ($taskIds) {
                    $q->where('subject_type', Task::class)->whereIn('subject_id', $taskIds);
                });
        })
            ->with('actor')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

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
