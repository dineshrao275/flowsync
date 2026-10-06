<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PerformanceGoalRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Task;
use App\Models\User;
use App\Services\Hrms\PerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Performance/HRMS — goals over HTTP.
 *
 * Goals live under their cycle in the URL; the employee travels in the
 * body on creation, and the policy answers self, manager, or view. Edits
 * past draft need manage (the policy), balanced sheets are enforced at
 * the check-in transition (the workflow), and a sealed cycle refuses new
 * goals outright — history does not gain commitments after the fact.
 */
class PerformanceGoalController extends Controller
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function index(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewAny', PerformanceGoal::class);

        $query = $cycle->goals()->with(['employee:id,employee_code,name'])->orderBy('id');

        if (! $this->seesAll($request->user())) {
            $mine = Employee::where('user_id', $request->user()->id)->value('id') ?? 0;
            $query->where('employee_id', $mine);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', (int) $request->query('employee_id'));
        }

        return response()->json([
            'goals' => $query->get()->map(fn (PerformanceGoal $goal): array => $this->present($goal))->all(),
        ]);
    }

    private function seesAll(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || $user->hasPermission('hrms.performance.manage')
            || $user->hasPermission('hrms.talent.manage');
    }

    public function show(PerformanceGoal $goal): JsonResponse
    {
        $this->authorize('view', $goal);

        return response()->json(['goal' => $this->present($goal)]);
    }

    public function store(PerformanceGoalRequest $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('create', PerformanceGoal::class);
        $this->requireOpen($cycle);

        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $this->authorize('file', [PerformanceGoal::class, $employee]);

        $data = $request->validated();

        $goal = $cycle->goals()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json([
            'message' => 'Goal created.',
            'goal' => $this->present($goal->refresh()),
        ], Response::HTTP_CREATED);
    }

    public function update(PerformanceGoalRequest $request, PerformanceGoal $goal): JsonResponse
    {
        $this->authorize('update', $goal);
        $this->requireOpen($goal->cycle);

        $goal->update($request->validated());

        return response()->json([
            'message' => 'Goal updated.',
            'goal' => $this->present($goal->refresh()),
        ]);
    }

    /**
     * Re-photograph one goal's evidence on demand: self, the manager, or
     * manage may ask — the refresh never writes a rating either way.
     */
    public function refresh(Request $request, PerformanceGoal $goal): JsonResponse
    {
        $this->authorize('refresh', $goal);

        return response()->json([
            'message' => 'Evidence refreshed.',
            'goal' => $this->present($this->performance->refreshGoalEvidence($goal)),
        ]);
    }

    /**
     * Attach a task as evidence, by id or by key (`PRJ-123` resolves like
     * the command palette's deep links). Linking answers to the goal's own
     * update ability — self while draft, or manage — because a link is an
     * edit to what the goal claims.
     */
    public function linkTask(Request $request, PerformanceGoal $goal): JsonResponse
    {
        $this->authorize('update', $goal);

        $validated = $request->validate([
            'task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
            'task_key' => ['sometimes', 'string', 'max:32'],
        ]);

        $task = isset($validated['task_id'])
            ? Task::findOrFail((int) $validated['task_id'])
            : Task::where('key', $validated['task_key'] ?? '')->firstOrFail();

        $this->performance->linkTask($goal, $task, $request->user());

        return response()->json([
            'message' => 'Task linked.',
            'goal' => $this->present($goal->refresh()),
        ], Response::HTTP_CREATED);
    }

    /**
     * Detach a task: both models ride the URL and belonging is verified,
     * so a task id from another goal 404s rather than unlinking wrong.
     */
    public function unlinkTask(Request $request, PerformanceGoal $goal, Task $task): JsonResponse
    {
        $this->authorize('update', $goal);
        abort_if(! $goal->taskLinks()->where('task_id', $task->id)->exists(), 404);

        $this->performance->unlinkTask($goal, $task, $request->user());

        return response()->json([
            'message' => 'Task unlinked.',
            'goal' => $this->present($goal->refresh()),
        ]);
    }

    /**
     * @throws HttpException on a sealed cycle
     */
    private function requireOpen(PerformanceCycle $cycle): void
    {
        abort_if($cycle->stage->value === 'completed', 422, 'That cycle is sealed — history gains no new goals.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PerformanceGoal $goal): array
    {
        $goal->loadMissing(['employee:id,employee_code,name', 'cycle:id,name,slug,period_start,period_end', 'taskLinks.task:id,key,title,status_id']);

        return [
            'id' => $goal->id,
            'cycle_id' => $goal->cycle_id,
            'employee_id' => $goal->employee_id,
            'employee' => $goal->employee ? [
                'id' => $goal->employee->id,
                'employee_code' => $goal->employee->employee_code,
                'name' => $goal->employee->displayName(),
            ] : null,
            'title' => $goal->title,
            'description' => $goal->description,
            'category' => $goal->category,
            'metric_type' => $goal->metric_type->value,
            'target_value' => $goal->target_value === null ? null : (string) $goal->target_value,
            'weight' => (string) $goal->weight,
            'due_date' => $goal->due_date?->toDateString(),
            'status' => $goal->status->value,
            'progress_percent' => (string) $goal->progress_percent,
            'progress_source' => $goal->progress_source->value,
            'progress_evidence' => $goal->progress_evidence,
            'achieved_at' => $goal->achieved_at?->toIso8601String(),
            'tasks' => $goal->taskLinks->map(fn ($link): ?array => $link->task === null ? null : [
                'id' => $link->task->id,
                'key' => $link->task->key,
                'title' => $link->task->title,
                'completed_at' => $link->task->completed_at?->toDateString(),
            ])->filter()->values()->all(),
        ];
    }
}
