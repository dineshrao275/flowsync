<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Employee/HRMS — moving a person to a new employment state.
 *
 * `to` is required rather than defaulted so the caller states the transition
 * instead of implying it: "these are the fields, you decide" is how an
 * offboarding form ends up re-activating somebody.
 *
 * `effective_date` is accepted in the past on purpose — backdating a resignation
 * to a Friday the person actually left is a normal correction, and refusing it
 * would push the real date into a free-text note nobody reads.
 */
class EmployeeStatusChangeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', Rule::enum(EmployeeStatus::class)],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
