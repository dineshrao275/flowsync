<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\WorkLog;
use Illuminate\Support\Carbon;

/**
 * Attendance/HRMS — marking days from voluntarily logged work.
 *
 * Opt-in per tenant (`attendance.auto_derive_from_work_logs`, default
 * false): work logs are volunteered, and a manager could otherwise
 * manufacture attendance from them, so derivation only runs where HR
 * explicitly switched it on. Every derived day is stamped
 * `is_regularized = false` with the note `derived:work_logs` — the note
 * is the derivation's identity, and a day carrying any other note (a real
 * punch run, a regularization) is never overwritten: real data wins over
 * derived data, and a re-run refreshes only its own rows.
 *
 * Closed logs only (an open timer has no measurable span), attributed by
 * their start date, summed to minutes. Logs filed by a login with no
 * employee record (service accounts) are skipped, never assigned.
 */
class WorkLogDerivation
{
    /**
     * @return array{disabled: bool, derived: int, skipped: int}
     */
    public function derive(Carbon $date, bool $persist = true): array
    {
        if (! $this->enabled()) {
            return ['disabled' => true, 'derived' => 0, 'skipped' => 0];
        }

        $derived = 0;
        $skipped = 0;

        foreach ($this->minutesByEmployee($date) as $employeeId => $row) {
            if ($persist) {
                $this->mark($employeeId, $date, $row) ? $derived++ : $skipped++;
            } else {
                $derived++;
            }
        }

        return ['disabled' => false, 'derived' => $derived, 'skipped' => $skipped];
    }

    public function enabled(): bool
    {
        $settings = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID);

        return (bool) ($settings?->setting('attendance.auto_derive_from_work_logs', false) ?? false);
    }

    /**
     * One row per active employee with closed logs on the date.
     *
     * @return array<int, array{minutes: int, first_in: Carbon, last_out: Carbon}>
     */
    private function minutesByEmployee(Carbon $date): array
    {
        $day = $date->toDateString();

        $logs = WorkLog::query()
            ->whereNotNull('ended_at')
            ->whereDate('started_at', $day)
            ->get(['user_id', 'started_at', 'ended_at', 'duration_minutes']);

        $employeeIds = Employee::query()->active()->whereNotNull('user_id')->pluck('id', 'user_id');

        $rows = [];

        foreach ($logs->groupBy('user_id') as $userId => $group) {
            $employeeId = $employeeIds->get($userId);

            if ($employeeId === null) {
                continue;
            }

            $rows[(int) $employeeId] = [
                'minutes' => (int) $group->sum('duration_minutes'),
                'first_in' => $group->min('started_at'),
                'last_out' => $group->max('ended_at'),
            ];
        }

        return $rows;
    }

    /**
     * Create or refresh this derivation's own row. Anything else already
     * standing on the date (punches, a regularization, a leave merge) is
     * left untouched and reported as skipped.
     *
     * The lookup is `whereDate`, never an exact `where`: the date cast
     * serializes with a time part on SQLite, so an exact match misses the
     * row and the insert dies on the unique index (the P5.2 lesson).
     *
     * @param  array{minutes: int, first_in: Carbon, last_out: Carbon}  $row
     */
    private function mark(int $employeeId, Carbon $date, array $row): bool
    {
        $day = AttendanceDay::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date->toDateString())
            ->first();

        if ($day === null) {
            $day = new AttendanceDay([
                'employee_id' => $employeeId,
                'work_date' => $date->toDateString(),
            ]);
        }

        if ($day->exists && $day->note !== 'derived:work_logs') {
            return false;
        }

        $day->fill([
            'status' => AttendanceDayStatus::Present,
            'worked_minutes' => $row['minutes'],
            'first_in_at' => $row['first_in'],
            'last_out_at' => $row['last_out'],
            'is_regularized' => false,
            'note' => 'derived:work_logs',
        ]);
        $day->save();

        return true;
    }
}
