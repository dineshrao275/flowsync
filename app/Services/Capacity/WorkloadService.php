<?php

namespace App\Services\Capacity;

use App\Models\Project;
use App\Models\User;
use App\Services\Tasks\TaskReader;
use App\Support\Hrms\HrmsSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Capacity vs planned work per project member over a date range.
 *
 *  - **Capacity** = working days (Mon–Fri) × hours per day (config `tms.capacity_hours_per_day`),
 *    minus approved leave when the tenant has HRMS (half days count half). Holidays are not
 *    subtracted yet — the holiday calendar is per employee and is a later refinement.
 *  - **Planned** = remaining estimate (estimate − logged, never below zero) of the member's open
 *    tasks due inside the range. Tasks with no due date are reported separately as `unscheduled`,
 *    and open tasks without an estimate are counted (`unestimated`) rather than guessed at.
 *
 * The task set comes from `TaskReader::filteredQuery`, so the caller only ever sees workload built
 * from tasks they may read (project-role row scope applies).
 */
class WorkloadService
{
    public function __construct(private readonly TaskReader $reader) {}

    /** @return array<string, mixed> */
    public function forProject(Project $project, CarbonImmutable $from, CarbonImmutable $to, User $viewer): array
    {
        $hours = (float) config('tms.capacity_hours_per_day', 8);
        $workingDays = $this->workingDays($from, $to);

        $tasks = $this->reader->filteredQuery($project, [], $viewer)
            ->whereNull('tasks.completed_at')
            ->whereNotNull('tasks.assignee_id')
            ->withSum('workLogs as logged_minutes', 'duration_minutes')
            ->get(['tasks.id', 'tasks.assignee_id', 'tasks.estimate_minutes', 'tasks.due_date', 'tasks.completed_at'])
            ->groupBy('assignee_id');

        $leave = $this->leaveDays($project, $from, $to);

        $members = $project->members()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
            ->map(function (User $member) use ($tasks, $leave, $workingDays, $hours, $from, $to): array {
                $mine = $tasks->get($member->id, collect());
                $inRange = $mine->filter(fn ($t) => $t->due_date !== null && $t->due_date->between($from->startOfDay(), $to->endOfDay()));

                $planned = (int) $inRange->sum(fn ($t) => $t->estimate_minutes === null ? 0 : max(0, $t->estimate_minutes - (int) $t->logged_minutes));
                $leaveDays = (float) ($leave[$member->id] ?? 0);
                $capacity = (int) round(max(0, $workingDays - $leaveDays) * $hours * 60);

                return [
                    'user' => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email],
                    'capacity_minutes' => $capacity,
                    'leave_days' => $leaveDays,
                    'planned_minutes' => $planned,
                    'utilization' => $capacity > 0 ? round($planned / $capacity, 3) : ($planned > 0 ? null : 0.0),
                    'overallocated' => $capacity > 0 ? $planned > $capacity : $planned > 0,
                    'open_tasks' => $inRange->count(),
                    'unestimated_tasks' => $inRange->whereNull('estimate_minutes')->count(),
                    'unscheduled_tasks' => $mine->whereNull('due_date')->count(),
                ];
            })->values();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'working_days' => $workingDays,
            'hours_per_day' => $hours,
            'members' => $members,
        ];
    }

    /** Mon–Fri days in the inclusive range. */
    public function workingDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $count = 0;
        for ($d = $from->startOfDay(); $d->lte($to->startOfDay()); $d = $d->addDay()) {
            $count += $d->isWeekday() ? 1 : 0;
        }

        return $count;
    }

    /**
     * Approved leave days per user id inside the range (weekdays only; half days count 0.5).
     *
     * @return array<int, float>
     */
    private function leaveDays(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! HrmsSchema::present()) {
            return [];
        }

        return DB::table('leave_request_days as d')
            ->join('leave_requests as r', 'r.id', '=', 'd.leave_request_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->where('r.status', 'approved')
            ->whereIn('e.user_id', $project->members()->pluck('users.id'))
            ->whereDate('d.date', '>=', $from->toDateString())
            ->whereDate('d.date', '<=', $to->toDateString())
            ->where('d.is_holiday', false)
            ->where('d.is_week_off', false)
            ->get(['e.user_id', 'd.date', 'd.is_half_day'])
            ->filter(fn ($row) => CarbonImmutable::parse($row->date)->isWeekday())
            ->groupBy('user_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($r) => $r->is_half_day ? 0.5 : 1.0))
            ->all();
    }
}
