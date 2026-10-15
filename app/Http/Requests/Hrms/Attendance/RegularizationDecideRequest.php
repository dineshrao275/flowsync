<?php

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attendance/HRMS — deciding a correction ask.
 *
 * One request for both verdicts: the note is optional on approval and
 * required on rejection, and only the service knows which verdict is being
 * entered, so it enforces the required-on-reject half.
 */
class RegularizationDecideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller checks `approve`/`reject` on the policy, which
        // answers from the approval step's own approver.
        return true;
    }
}
