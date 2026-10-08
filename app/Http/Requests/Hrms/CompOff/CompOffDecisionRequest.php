<?php

namespace App\Http\Requests\Hrms\CompOff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CompOff/HRMS — deciding a redemption ask.
 *
 * The note is optional on approval and required on rejection, and only the
 * service knows which verdict is being entered, so it enforces the
 * required-on-reject half.
 */
class CompOffDecisionRequest extends FormRequest
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
        // The controller checks approve/reject on the policy, which answers
        // from the approval step's own approver.
        return true;
    }
}
