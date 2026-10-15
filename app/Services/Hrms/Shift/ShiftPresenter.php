<?php

namespace App\Services\Hrms\Shift;

use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;

/**
 * Shift/HRMS — what shifts and roster rows look like over HTTP.
 */
class ShiftPresenter
{
    /** @return array<string, mixed> */
    public function shift(AttendanceShift $shift): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'code' => $shift->code,
            'description' => $shift->description,
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'break_minutes' => $shift->break_minutes,
            'grace_minutes' => $shift->grace_minutes,
            'min_hours' => $shift->min_hours,
            'is_night' => $shift->is_night,
            'is_active' => $shift->is_active,
            'is_system' => $shift->is_system,
            'position' => $shift->position,
            'working_days' => $shift->working_days ?? [],
            'segments' => $shift->segments ?? [],
            'is_split' => ! empty($shift->segments),
            'rosters_count' => (int) ($shift->rosters_count ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function roster(AttendanceRoster $roster): array
    {
        return [
            'id' => $roster->id,
            'employee_id' => $roster->employee_id,
            'employee_name' => $roster->employee?->displayName(),
            'shift_id' => $roster->shift_id,
            'shift_name' => $roster->shift?->name,
            'effective_from' => $roster->effective_from->toDateString(),
            'effective_to' => $roster->effective_to?->toDateString(),
            'weekly_offs' => $roster->weekly_offs ?? [],
            'is_flexible' => $roster->is_flexible,
        ];
    }
}
