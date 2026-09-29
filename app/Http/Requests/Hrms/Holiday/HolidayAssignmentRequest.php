<?php

namespace App\Http\Requests\Hrms\Holiday;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Holiday/HRMS — following a calendar.
 *
 * Windows chain (the service refuses overlaps): an open-ended follow ends
 * the previous era implicitly, and a bounded one carves history neither
 * side rewrites.
 */
class HolidayAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'calendar_id' => ['required', 'integer', 'exists:holiday_calendars,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes `create` (manage-only); this is only
        // the framework's pre-check.
        return true;
    }
}
