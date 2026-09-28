<?php

namespace App\Services\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;

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
}
