<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Task;
use App\Services\Hrms\Attendance\WorkLogDerivation;
use App\Support\Hrms\HrmsSchema;
use App\Support\Hrms\HrmsTaskLinks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `protect_hrms:<guard>[,<guard>]` — keeps TMS writes made through an API token from changing HR data.
 *
 *  - `linked_task`: the task is linked to HR (goal evidence, an HR task link, an HR-owned ask), so it is read-only.
 *    Completing such a task would otherwise refresh the linked performance goal's evidence.
 *  - `attendance`: while HR derives attendance from work logs, a log written through the API would feed an
 *    attendance record, so time has to be logged in the app.
 */
class ProtectHrmsData
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if (in_array('linked_task', $guards, true) && $this->isHrLinked($request->route('task'))) {
            return $this->refuse('hrms_linked_task_read_only', 'This task is linked to HR data (a performance goal or an HR task link), so it is read-only through the API. Change it in the app.');
        }

        if (in_array('attendance', $guards, true) && HrmsSchema::present() && app(WorkLogDerivation::class)->enabled()) {
            return $this->refuse('hrms_attendance_derivation_enabled', 'HR derives attendance from work logs in this workspace, so time cannot be logged through the API. Log it in the app.');
        }

        return $next($request);
    }

    private function isHrLinked(mixed $task): bool
    {
        $task = $task instanceof Task ? $task : (is_numeric($task) ? Task::query()->find((int) $task) : null);

        return $task !== null && HrmsTaskLinks::isLinked($task);
    }

    private function refuse(string $code, string $message): Response
    {
        return response()->json(['message' => $message, 'code' => $code], 403);
    }
}
