<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Holiday\HolidayService;
use App\Services\Hrms\Leave\LeaveCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance/HRMS — reading days back out.
 *
 * Split from {@see DayComputation} because computing a day and reading one
 * are different directions: the computation writes the photograph from the
 * punches, and this merges that photograph with everything else a date
 * means (weekly offs; approved leave since P6.2c; holidays when P8 lands).
 * Reads stay pure here — a reader that also writes is how two code paths
 * disagree about a day.
 *
 * The leave merge reads through `LeaveCalendar`, never the leave
 * tables directly: the Attendance context may call the Leave context's
 * service (D2.16.2), and the calendar owns no attendance dependency,
 * so the edge stays acyclic.
 */
class DayReading
{
    public function __construct(
        private readonly DayComputation $days,
        private readonly LeaveCalendar $leave,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * The merged status for a date: attendance first, then the overlays.
     *
     * Weekly offs merge; approved leave merges (an approved ask with no
     * punches behind it is `leave`, never `absent`); holidays merged in P8.3
     * (a public or taken-optional day with no punches is `holiday`). A day
     * with punches is never a week-off, a leave, or a holiday: someone who
     * clocked in worked, whatever the roster, the ask, or the calendar says.
     */
    public function dayStatus(Employee $employee, Carbon|string $date): AttendanceDayStatus
    {
        $day = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        [, $roster] = $this->days->resolveShift($employee, $day);

        // whereDate, not where: same SQLite typeless-column trap as the
        // computation — the `date` cast stores a time part.
        $record = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->first();

        $hasPunches = $record !== null && ($record->first_in_at !== null || $record->last_out_at !== null);

        if (! $hasPunches && $this->matchesWeeklyOff($roster, $day)) {
            return AttendanceDayStatus::WeekOff;
        }

        if (! $hasPunches && $this->isOnApprovedLeave($employee, $day)) {
            return AttendanceDayStatus::Leave;
        }

        if (! $hasPunches && $this->holidays->isHoliday($employee, $day)) {
            return AttendanceDayStatus::Holiday;
        }

        return $record?->status ?? AttendanceDayStatus::Absent;
    }

    /**
     * Whether a date is the employee's weekly off under the active roster.
     *
     * Public for the Leave context: the day split excludes weekly offs from
     * charged totals, and the roster is attendance-owned — Leave reads it
     * through this service edge (D2.16.2), never the roster table.
     */
    public function isWeekOff(Employee $employee, Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        [, $roster] = $this->days->resolveShift($employee, $day);

        return $this->matchesWeeklyOff($roster, $day);
    }

    /**
     * Totals over a date range, for the month grid and the payroll inputs.
     *
     * @return array{days: int, present: int, absent: int, half_day: int, late: int, leave: int, holiday: int, week_off: int, worked_minutes: int, late_minutes: int, overtime_minutes: int}
     */
    public function summary(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        // whereDate pairs, not whereBetween: same trap — a 'Y-m-d' upper
        // bound string-compares below the stored 'Y-m-d H:i:s' value and
        // silently drops the last day of the range.
        $rows = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from instanceof Carbon ? $from->toDateString() : $from)
            ->whereDate('work_date', '<=', $to instanceof Carbon ? $to->toDateString() : $to)
            ->get();

        // One range query per overlay for the whole window, flipped for O(1)
        // lookup in the loop below — never a query per row.
        $leaveDates = array_flip($this->leave->approvedLeaveDates($employee, $from, $to));
        $holidayDates = array_flip($this->holidays->holidayDates($employee, $from, $to));

        $counts = [
            'days' => $rows->count(),
            'present' => 0, 'absent' => 0, 'half_day' => 0, 'late' => 0,
            'leave' => 0, 'holiday' => 0, 'week_off' => 0,
            'worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0,
        ];

        foreach ($rows as $row) {
            $hasPunches = $row->first_in_at !== null || $row->last_out_at !== null;

            // Overlays photograph as `absent` but read as their kind — never
            // a rewrite. Leave before holidays: an approved ask already
            // excludes holidays from its split, so the two sets rarely meet,
            // and the ask wins the tie it cannot usually tie.
            $key = match (true) {
                $row->status === AttendanceDayStatus::Present => 'present',
                $row->status === AttendanceDayStatus::HalfDay => 'half_day',
                $row->status === AttendanceDayStatus::Late => 'late',
                $row->status === AttendanceDayStatus::Leave => 'leave',
                $row->status === AttendanceDayStatus::Holiday => 'holiday',
                $row->status === AttendanceDayStatus::WeekOff => 'week_off',
                ! $hasPunches && isset($leaveDates[$row->work_date->toDateString()]) => 'leave',
                ! $hasPunches && isset($holidayDates[$row->work_date->toDateString()]) => 'holiday',
                default => 'absent',
            };

            $counts[$key]++;

            $counts['worked_minutes'] += $row->worked_minutes;
            $counts['late_minutes'] += $row->late_by_minutes;
            $counts['overtime_minutes'] += $row->overtime_minutes;
        }

        return $counts;
    }

    private function matchesWeeklyOff(?AttendanceRoster $roster, Carbon $day): bool
    {
        $offs = $roster?->weekly_offs;

        if (! is_array($offs)) {
            return false;
        }

        // Monday-first: Carbon’s dayOfWeekIso runs 1–7, the array runs 0–6.
        return (bool) ($offs[$day->copy()->tz($this->days->tenantTimezone())->dayOfWeekIso - 1] ?? false);
    }

    /**
     * Whether the employee holds an approved ask over a date.
     *
     * One range query per caller, never per date: the month grid and the
     * summary hand a window, `dayStatus` hands a single day as a degenerate
     * window.
     */
    private function isOnApprovedLeave(Employee $employee, Carbon $day): bool
    {
        return $this->leave->approvedLeaveDates($employee, $day, $day) !== [];
    }

    /**
     * Whether any punch was recorded for a date.
     *
     * The comp-off accrual's skip rule reads through here: worked time
     * stays in attendance and payroll, so a date with punches behind it
     * never banks rest time on top.
     */
    public function hasPunches(Employee $employee, Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        return AttendancePunch::forEmployee($employee->id)->whereDate('punch_at', $day)->exists();
    }

    /**
     * One employee's month for the grid: every lived date carries its merged
     * status, future dates are omitted (the grid blanks them as upcoming).
     *
     * Pure like every other read here — missing past dates read `absent`
     * (or `week_off`, or `leave` under an approved ask) without writing a
     * row; the rollup owns materializing.
     * Rosters and rows are preloaded once, so the per-date loop issues no
     * queries no matter how far back the month is.
     *
     * @return array{year: int, month: int, days: list<array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null}>, summary: array{days: int, present: int, absent: int, half_day: int, late: int, leave: int, holiday: int, week_off: int, worked_minutes: int, late_minutes: int, overtime_minutes: int}}
     */
    public function month(Employee $employee, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = today()->startOfDay();

        if ($end->greaterThan($today)) {
            $end = $today;
        }

        $rows = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (AttendanceDay $row): string => $row->work_date->toDateString());

        $rosters = AttendanceRoster::where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start->toDateString()))
            ->with('shift')
            ->orderBy('effective_from')
            ->get();

        $days = [];
        $leaveDates = array_flip($this->leave->approvedLeaveDates($employee, $start, $end));
        $holidayDates = array_flip($this->holidays->holidayDates($employee, $start, $end));

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $record = $rows->get($date->toDateString());
            $hasPunches = $record !== null && ($record->first_in_at !== null || $record->last_out_at !== null);

            if (! $hasPunches && $this->matchesWeeklyOff($this->rosterOn($rosters, $date), $date)) {
                $status = AttendanceDayStatus::WeekOff;
            } elseif (! $hasPunches && isset($leaveDates[$date->toDateString()])) {
                $status = AttendanceDayStatus::Leave;
            } elseif (! $hasPunches && isset($holidayDates[$date->toDateString()])) {
                $status = AttendanceDayStatus::Holiday;
            } else {
                $status = $record?->status ?? AttendanceDayStatus::Absent;
            }

            $days[] = ['date' => $date->toDateString(), 'status' => $status, 'record' => $record];
        }

        return [
            'year' => $year,
            'month' => $month,
            'days' => $days,
            'summary' => $this->summary($employee, $start, $end),
        ];
    }

    /**
     * Today for the clock widget: the merged status, the stored row if the
     * rollup or a punch already created it, and today's punches in order.
     *
     * @return array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null, punches: Collection<int, AttendancePunch>}
     */
    public function today(Employee $employee): array
    {
        $date = today()->startOfDay();

        return [
            'date' => $date->toDateString(),
            'status' => $this->dayStatus($employee, $date),
            'record' => AttendanceDay::where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->first(),
            'punches' => AttendancePunch::forEmployee($employee->id)
                ->whereDate('punch_at', $date->toDateString())
                ->orderBy('punch_at')
                ->get(),
        ];
    }

    /**
     * The roster covering a date out of a preloaded set: the latest to take
     * effect on or before it that has not ended. Same answer `resolveShift`
     * gives, without a query per date.
     *
     * @param  Collection<int, AttendanceRoster>  $rosters
     */
    private function rosterOn(Collection $rosters, Carbon $date): ?AttendanceRoster
    {
        $day = $date->toDateString();

        return $rosters
            ->filter(fn (AttendanceRoster $roster): bool => $roster->effective_from->toDateString() <= $day
                && ($roster->effective_to === null || $roster->effective_to->toDateString() >= $day))
            ->last();
    }
}
