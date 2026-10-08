<?php

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attendance/HRMS — asking for a past day to be corrected.
 *
 * Shape only: the window (how far back), the same-date rule for corrected
 * times, and the at-least-one-time rule live in the service, where they can
 * read settings and existing asks. `work_date` is a plain date — the
 * corrected times carry the clock readings.
 */
class RegularizationStoreRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            'requested_first_in_at' => ['sometimes', 'nullable', 'date'],
            'requested_punch_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        // Self-scoped by design: any employee may ask for their own record.
        // The controller checks `create` on the policy.
        return true;
    }
}
