<?php

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attendance/HRMS — the tenant’s clock-in policy.
 *
 * Two JSON sections, validated by shape: `attendance` carries the math the
 * day computation reads (thresholds in minutes, the rounding step, whether
 * undertime may go negative), and `remote_clock_in` carries the admission
 * policy the punch endpoint enforces. Unknown keys are dropped by
 * `validated()`, so a client cannot smuggle a third section into the row —
 * settings the engine does not read are settings nobody can reason about.
 */
class AttendanceSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'attendance' => ['sometimes', 'array'],
            'attendance.rounding_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'attendance.ot_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'attendance.half_day_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'attendance.full_day_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'attendance.allow_negative_ot' => ['sometimes', 'boolean'],
            'attendance.auto_derive_from_work_logs' => ['sometimes', 'boolean'],
            'attendance.regularization_window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'remote_clock_in' => ['sometimes', 'array'],
            'remote_clock_in.enabled' => ['sometimes', 'boolean'],
            'remote_clock_in.require_ip' => ['sometimes', 'boolean'],
            'remote_clock_in.allowed_ips' => ['sometimes', 'array'],
            'remote_clock_in.allowed_ips.*' => ['string', 'max:64'],
            'remote_clock_in.require_geofence' => ['sometimes', 'boolean'],
            'remote_clock_in.out_of_range_action' => ['sometimes', 'string', 'in:flag,block'],
            'remote_clock_in.max_distance_meters' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // data_set + replace, not merge: merge() with a dotted key writes a
        // literal 'attendance.allow_negative_ot' top-level key instead of
        // nesting it, and validation would then read the un-normalised
        // original sitting underneath.
        $input = $this->all();

        foreach (['attendance.allow_negative_ot', 'attendance.auto_derive_from_work_logs', 'remote_clock_in.enabled', 'remote_clock_in.require_ip', 'remote_clock_in.require_geofence'] as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);
            $filtered = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : $value;

            data_set($input, $key, $filtered ?? $value);
        }

        $this->replace($input);
    }

    public function authorize(): bool
    {
        // The route gates on `hrms.attendance.settings`; this is only the
        // framework’s pre-check.
        return true;
    }
}
