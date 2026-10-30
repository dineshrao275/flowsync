<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\WorkLog;
use App\Services\ActivityLogger;
use App\Services\Time\TimesheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Start/stop timer. A timer is a work log with no `ended_at`; each person has at most one — starting
 * a second stops the first, so the clock can never run on two tasks at once.
 */
class TimerController extends Controller
{
    public function __construct(private readonly TimesheetService $timesheets, private readonly ActivityLogger $logger) {}

    public function current(Request $request): JsonResponse
    {
        return response()->json(['timer' => $this->present($this->running($request))]);
    }

    public function start(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('create', [WorkLog::class, $task]);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'billable' => ['sometimes', 'boolean'],
        ]);

        $this->timesheets->assertOpen($request->user()->id, now());

        $stopped = $this->running($request);
        if ($stopped !== null) {
            $this->finish($stopped, $request);
        }

        $log = WorkLog::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'started_at' => now(),
            'ended_at' => null,
            'duration_minutes' => 1,
            'description' => $data['description'] ?? null,
            'billable' => $data['billable'] ?? true,
        ]);

        return response()->json([
            'message' => __('Timer started.'),
            'timer' => $this->present($log),
            'stopped' => $stopped ? $this->present($stopped->refresh()) : null,
        ], 201);
    }

    public function stop(Request $request): JsonResponse
    {
        $log = $this->running($request);

        if ($log === null) {
            return response()->json(['message' => __('No timer is running.'), 'timer' => null]);
        }

        $this->finish($log, $request);

        return response()->json(['message' => __('Timer stopped.'), 'timer' => null, 'stopped' => $this->present($log->refresh())]);
    }

    private function running(Request $request): ?WorkLog
    {
        return WorkLog::query()->with('task:id,key,title,project_id')
            ->where('user_id', $request->user()->id)->whereNull('ended_at')->latest('started_at')->first();
    }

    private function finish(WorkLog $log, Request $request): void
    {
        $end = now();
        $log->update([
            'ended_at' => $end,
            'duration_minutes' => max(1, (int) round(abs($end->diffInSeconds($log->started_at)) / 60)),
        ]);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $log->task_id,
            action: 'task.work_logged',
            data: ['work_log_id' => $log->id, 'duration_minutes' => $log->duration_minutes, 'started_at' => $log->started_at?->toISOString(), 'source' => 'timer'],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );
    }

    /** @return array<string, mixed>|null */
    private function present(?WorkLog $log): ?array
    {
        return $log === null ? null : [
            'id' => $log->id,
            'task_id' => $log->task_id,
            'task_key' => $log->task?->key,
            'task_title' => $log->task?->title,
            'project_id' => $log->task?->project_id,
            'started_at' => $log->started_at?->toISOString(),
            'ended_at' => $log->ended_at?->toISOString(),
            'duration_minutes' => $log->duration_minutes,
            'billable' => (bool) $log->billable,
            'description' => $log->description,
        ];
    }
}
