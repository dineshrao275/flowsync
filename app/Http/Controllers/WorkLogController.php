<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\WorkLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkLogController extends Controller
{
    public function __construct(
        private readonly WorkLogService $service,
        private readonly ActivityLogger $logger,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $logs = $this->service->taskLogs($task);

        return response()->json([
            'work_logs' => collect($logs->items())
                ->map(fn (WorkLog $log) => $this->present($log))
                ->values(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'totals' => $this->service->taskAggregate($task),
            'my_role' => $project->memberRole($request->user())?->slug,
        ]);
    }

    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('create', [WorkLog::class, $task]);

        $data = $this->validated($request);

        $log = $this->service->create($task, $data, $request->user());

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.work_logged',
            data: [
                'work_log_id' => $log->id,
                'duration_minutes' => $log->duration_minutes,
                'started_at' => $log->started_at?->toISOString(),
            ],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        $this->notifications->workLogAdded($request->user(), $task, $log);

        return response()->json([
            'message' => 'Work log added.',
            'work_log' => $this->present($log),
        ], 201);
    }

    public function update(Request $request, Project $project, Task $task, WorkLog $workLog): JsonResponse
    {
        if ($workLog->task_id !== $task->id) {
            abort(404);
        }

        $this->authorize('update', $workLog);

        $data = $this->validated($request);

        $log = $this->service->update($workLog, $data);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.work_log_updated',
            data: ['work_log_id' => $log->id],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'message' => 'Work log updated.',
            'work_log' => $this->present($log),
        ]);
    }

    public function destroy(Request $request, Project $project, Task $task, WorkLog $workLog): JsonResponse
    {
        if ($workLog->task_id !== $task->id) {
            abort(404);
        }

        $this->authorize('delete', $workLog);

        $id = $workLog->id;
        $this->service->delete($workLog);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.work_log_deleted',
            data: ['work_log_id' => $id],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json(['message' => 'Work log removed.']);
    }

    public function projectTime(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'summary' => $this->service->projectSummary($project, $this->summaryFilters($request)),
        ]);
    }

    public function workspaceTime(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'summary' => $this->service->workspaceSummary($workspace, $this->summaryFilters($request)),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after:started_at'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function summaryFilters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group_by' => ['nullable', 'in:user,status,date'],
            'user_id' => ['nullable', 'integer'],
        ]);
    }

    private function present(WorkLog $log): array
    {
        $log->loadMissing('user:id,name,email');

        return [
            'id' => $log->id,
            'started_at' => $log->started_at?->toISOString(),
            'ended_at' => $log->ended_at?->toISOString(),
            'duration_minutes' => $log->duration_minutes,
            'effective_minutes' => $this->service->effectiveMinutes($log),
            'description' => $log->description,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
        ];
    }
}
