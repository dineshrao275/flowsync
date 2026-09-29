<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveRequestDay;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave/HRMS — which asks a viewer may list.
 *
 * The set-level half of the policy question (the DocumentDirectoryQuery
 * precedent): per-record answers live in the policy, the list scope lives
 * here, and both read the same permissions so they cannot disagree. A
 * viewer with `hrms.leave.view` (or manage) sees every ask; anyone else
 * sees only their own employment record's.
 */
class LeaveRequestDirectory
{
    public function __construct(private readonly ReportingLine $reporting) {}

    /**
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, LeaveRequest>
     */
    public function listFor(User $viewer, array $filters = []): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee:id,name,employee_code', 'type:id,name,code', 'approval:id,status,current_step'])
            ->orderByDesc('id');

        if (! $this->mayReviewAll($viewer)) {
            $employeeId = Employee::where('user_id', $viewer->id)->value('id');

            $query->where('employee_id', $employeeId ?? -1);
        } elseif (isset($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function mayReviewAll(User $viewer): bool
    {
        return $viewer->hasPermission('hrms.leave.view')
            || $viewer->hasPermission('hrms.leave.manage');
    }

    /**
     * Approved leave across a month for the viewer's team: themselves plus
     * their direct reports — the people whose cover the request modal shows
     * before filing. HR sees its reports, not the whole tenant; the
     * tenant-wide view belongs to analytics (P18), not to a filing modal.
     *
     * One query for the window, grouped in PHP: the modal renders a month,
     * not a ledger.
     *
     * @return array<string, list<array{employee_id: int, employee_name: string, type_name: string}>>
     */
    public function teamCalendar(Employee $viewer, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        $teamIds = [$viewer->id];

        foreach ($this->reporting->reportsOf($viewer) as $report) {
            $teamIds[] = $report->id;
        }

        $rows = LeaveRequestDay::query()
            ->whereHas('request', fn (Builder $query) => $query
                ->whereIn('employee_id', $teamIds)
                ->where('status', 'approved'))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->where('is_week_off', false)
            ->where('is_holiday', false)
            ->with(['request.employee:id,name', 'request.type:id,name'])
            ->orderBy('date')
            ->get();

        $calendar = [];

        foreach ($rows as $row) {
            // pluck() would skip casts; here the model carries them, but the
            // SQLite time part rides the attribute either way — parse it off.
            $date = Carbon::parse((string) $row->date)->toDateString();

            $calendar[$date][] = [
                'employee_id' => $row->request->employee_id,
                'employee_name' => $row->request->employee?->name ?? 'Unknown',
                'type_name' => $row->request->type?->name ?? 'Leave',
            ];
        }

        return $calendar;
    }
}
