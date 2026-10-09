<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shift/HRMS — creating or editing a shift.
 *
 * One request for both verbs (`requiredIf` on POST). Times are `H:i`
 * time-of-day; `segments` describes a split shift and, when present, replaces
 * start/end/break (the service derives them). Segments must not overlap.
 */
class ShiftRequest extends FormRequest
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $create = fn (): bool => $this->isMethod('POST') && $this->input('segments') === null;

        return [
            'name' => [Rule::requiredIf($this->isMethod('POST')), 'string', 'max:255'],
            'code' => [
                Rule::requiredIf($this->isMethod('POST')), 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('attendance_shifts', 'code')->ignore($this->route('shift')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_time' => [Rule::requiredIf($create), 'date_format:H:i'],
            'end_time' => [Rule::requiredIf($create), 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:600'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'min_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:24'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'working_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'working_days.*' => ['string', Rule::in(self::DAYS), 'distinct'],
            'segments' => ['sometimes', 'nullable', 'array', 'min:2', 'max:4'],
            'segments.*.start' => ['required', 'date_format:H:i'],
            'segments.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $segments = $this->input('segments');
            if (! is_array($segments) || $validator->errors()->isNotEmpty()) {
                return;
            }

            usort($segments, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            foreach ($segments as $i => $segment) {
                $next = $segments[$i + 1] ?? null;
                if ($segment['end'] <= $segment['start'] || ($next !== null && $next['start'] < $segment['end'])) {
                    $validator->errors()->add('segments', 'Segments must run forward within a day and must not overlap.');

                    return;
                }
            }
        }];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy.
        return true;
    }
}
