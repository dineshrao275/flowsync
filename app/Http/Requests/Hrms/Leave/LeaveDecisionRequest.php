<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — deciding an ask.
 *
 * One request for approvals, rejections and exemption verdicts: the note is
 * optional on approval and required on rejection, and only the service
 * knows which verdict is being entered, so it enforces the required-on-
 * reject half. The exemption verdict rides `decision` (the
 * RegularizationDecideRequest precedent splits endpoints; exemptions share
 * one).
 */
class LeaveDecisionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['sometimes', Rule::in(['approve', 'reject'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller checks approve/reject/decide on the policy, which
        // answers from the approval step's own approver.
        return true;
    }
}
