<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use Illuminate\Support\Collection;

/**
 * Attendance/HRMS — what a punch and a day look like over HTTP.
 *
 * One home for both shapes so the punch endpoint, the month grid and the
 * approvals queue cannot drift into three dialects for the same rows. Times
 * render ISO-8601; minutes stay minutes — the client formats durations, the
 * server never ships pre-formatted strings it cannot sort by.
 */
class DayPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function punch(AttendancePunch $punch): array
    {
        return [
            'id' => $punch->id,
            'employee_id' => $punch->employee_id,
            'punch_at' => $punch->punch_at->toISOString(),
            'direction' => $punch->direction->value,
            'kind' => $punch->kind?->value ?? 'work',
            'source' => $punch->source->value,
            'is_out_of_range' => $punch->is_out_of_range,
            'out_of_range_reason' => $punch->out_of_range_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function day(AttendanceDay $day): array
    {
        return [
            'id' => $day->id,
            'employee_id' => $day->employee_id,
            'work_date' => $day->work_date->toDateString(),
            'status' => $day->status->value,
            'status_label' => $day->status->label(),
            'first_in_at' => $day->first_in_at?->toISOString(),
            'last_out_at' => $day->last_out_at?->toISOString(),
            'worked_minutes' => $day->worked_minutes,
            'break_minutes' => $day->break_minutes,
            'late_by_minutes' => $day->late_by_minutes,
            'early_by_minutes' => $day->early_by_minutes,
            'overtime_minutes' => $day->overtime_minutes,
            'is_regularized' => $day->is_regularized,
        ];
    }

    /**
     * One month-grid cell: the merged status always, the stored row's
     * details only when the rollup or a punch created one. A dateless cell
     * (absent without a row) is still a cell — the grid renders status, not
     * row existence.
     *
     * @param  array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null}  $entry
     * @return array<string, mixed>
     */
    public function monthEntry(array $entry): array
    {
        $record = $entry['record'];

        return [
            'date' => $entry['date'],
            'status' => $entry['status']->value,
            'status_label' => $entry['status']->label(),
            'has_record' => $record !== null,
            'first_in_at' => $record?->first_in_at?->toISOString(),
            'last_out_at' => $record?->last_out_at?->toISOString(),
            'worked_minutes' => $record?->worked_minutes ?? 0,
            'late_by_minutes' => $record?->late_by_minutes ?? 0,
            'overtime_minutes' => $record?->overtime_minutes ?? 0,
            'is_regularized' => $record?->is_regularized ?? false,
        ];
    }

    /**
     * @param  array{year: int, month: int, days: list<array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null}>, summary: array<string, int>}  $month
     * @return array<string, mixed>
     */
    public function month(array $month): array
    {
        return [
            'year' => $month['year'],
            'month' => $month['month'],
            'days' => array_map(fn (array $entry): array => $this->monthEntry($entry), $month['days']),
            'summary' => $month['summary'],
        ];
    }

    /**
     * @param  array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null, punches: Collection<int, AttendancePunch>}  $today
     * @return array<string, mixed>
     */
    public function today(array $today): array
    {
        return [
            'date' => $today['date'],
            'status' => $today['status']->value,
            'status_label' => $today['status']->label(),
            'day' => $today['record'] === null ? null : $this->day($today['record']),
            'punches' => $today['punches']->map(fn (AttendancePunch $punch): array => $this->punch($punch))->all(),
        ];
    }
}
