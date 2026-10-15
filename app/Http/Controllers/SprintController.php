<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Services\Sprints\AgileReports;
use App\Services\Sprints\SprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sprints, the backlog and agile reports of a project. Reading is for members; planning needs project settings rights. */
class SprintController extends Controller
{
    public function __construct(
        private readonly SprintService $sprints,
        private readonly AgileReports $reports,
    ) {}

    /** Sprints (newest first) with their task counts/points, plus the unplanned backlog. */
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $sprints = $project->sprints()->withCount('tasks')->withSum('tasks as points', 'story_points')->orderByRaw("case status when 'active' then 0 when 'planned' then 1 else 2 end")->orderByDesc('id')->get();

        return response()->json([
            'sprints' => $sprints->map(fn (Sprint $s) => $this->present($s)),
            'backlog' => $this->taskRows($project->tasks()->whereNull('sprint_id')->whereNull('parent_id')->whereNull('completed_at')->orderByDesc('id')->limit(200)->with(['status', 'assignee', 'priority'])->get()),
        ]);
    }

    public function show(Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('view', $project);
        $this->own($project, $sprint);

        return response()->json([
            'sprint' => $this->present($sprint->loadCount('tasks')),
            'tasks' => $this->taskRows($sprint->tasks()->with(['status', 'assignee', 'priority'])->orderBy('id')->get()),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);
        $sprint = $this->sprints->create($project, $this->validated($request), $request->user()->id);

        return response()->json(['message' => 'Sprint created.', 'sprint' => $this->present($sprint)], 201);
    }

    public function update(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);
        abort_if($sprint->status === Sprint::COMPLETED, 422, 'A completed sprint cannot be edited.');
        $sprint->update($this->validated($request));

        return response()->json(['message' => 'Sprint saved.', 'sprint' => $this->present($sprint->refresh())]);
    }

    public function start(Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);

        return response()->json(['message' => 'Sprint started.', 'sprint' => $this->present($this->sprints->start($sprint))]);
    }

    public function complete(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);
        $data = $request->validate([
            'leftover' => ['required', Rule::in(['backlog', 'sprint'])],
            'target_sprint_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['message' => 'Sprint completed.', 'sprint' => $this->present($this->sprints->complete($sprint, $data['leftover'], $data['target_sprint_id'] ?? null))]);
    }

    public function destroy(Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);
        $this->sprints->delete($sprint);

        return response()->json(['message' => 'Sprint deleted.']);
    }

    public function addTasks(Request $request, Project $project, Sprint $sprint): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);
        $data = $request->validate(['task_ids' => ['required', 'array', 'min:1', 'max:200'], 'task_ids.*' => ['integer']]);

        return response()->json(['message' => 'Planned.', 'added' => $this->sprints->addTasks($sprint, $data['task_ids'])]);
    }

    public function removeTask(Project $project, Sprint $sprint, Task $task): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->own($project, $sprint);
        abort_unless($task->project_id === $project->id, 404);
        $this->sprints->removeTask($sprint, $task);

        return response()->json(['message' => 'Moved back to the backlog.']);
    }

    public function reports(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $sprintId = $request->query('sprint');
        $sprint = $sprintId
            ? $project->sprints()->findOrFail((int) $sprintId)
            : ($project->sprints()->where('status', Sprint::ACTIVE)->first() ?? $project->sprints()->where('status', Sprint::COMPLETED)->orderByDesc('completed_at')->first());

        return response()->json($this->reports->forProject($project, $sprint) + ['sprint' => $sprint ? ['id' => $sprint->id, 'name' => $sprint->name, 'status' => $sprint->status] : null]);
    }

    private function own(Project $project, Sprint $sprint): void
    {
        abort_unless($sprint->project_id === $project->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'goal' => ['nullable', 'string', 'max:1000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Sprint $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'goal' => $s->goal, 'status' => $s->status,
            'start_date' => $s->start_date?->toDateString(), 'end_date' => $s->end_date?->toDateString(),
            'started_at' => $s->started_at?->toIso8601String(), 'completed_at' => $s->completed_at?->toIso8601String(),
            'committed_points' => $s->committed_points, 'completed_points' => $s->completed_points,
            'tasks_count' => $s->tasks_count ?? null, 'points' => isset($s->points) ? (float) $s->points : null,
        ];
    }

    /** @param iterable<Task> $tasks */
    private function taskRows(iterable $tasks): array
    {
        return collect($tasks)->map(fn (Task $t) => [
            'id' => $t->id, 'key' => $t->key, 'title' => $t->title, 'story_points' => $t->story_points, 'sprint_id' => $t->sprint_id,
            'status' => $t->status ? ['id' => $t->status->id, 'name' => $t->status->name, 'is_done' => $t->status->is_done] : null,
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'priority' => $t->priority ? ['name' => $t->priority->name, 'color' => $t->priority->color] : null,
        ])->values()->all();
    }
}
