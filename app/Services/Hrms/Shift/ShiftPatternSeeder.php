<?php

namespace App\Services\Hrms\Shift;

use App\Models\Hrms\Attendance\AttendanceShift;

/**
 * Shift/HRMS — starter shift patterns from `config('hrms.shift_patterns')`.
 *
 * Insert-only and keyed on `code`: re-provisioning adds a pattern the catalogue
 * later gains and never resets a tenant's own tuning. The catalogue speaks in
 * `days` (weekday slugs) and an unflagged `end_time`; the table stores
 * `working_days` and derives `is_night` from start/end — mapped explicitly here
 * rather than letting a config key leak into a column that is not its own.
 */
class ShiftPatternSeeder
{
    public function seed(): void
    {
        foreach ((array) config('hrms.shift_patterns', []) as $index => $pattern) {
            if (AttendanceShift::query()->where('code', $pattern['code'])->exists()) {
                continue;
            }

            AttendanceShift::create([
                'name' => $pattern['name'],
                'code' => $pattern['code'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'break_minutes' => $pattern['break_minutes'] ?? 0,
                'is_night' => $pattern['end_time'] <= $pattern['start_time'],
                'is_system' => (bool) ($pattern['is_system'] ?? false),
                'working_days' => $pattern['days'] ?? null,
                'is_active' => true,
                'position' => ($index + 1) * 10,
            ]);
        }
    }
}
