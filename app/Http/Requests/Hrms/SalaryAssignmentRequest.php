<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Compensation/HRMS — pricing a person from a date.
 *
 * `structure_id` and the money travel here; the employee travels in the
 * URL (the Lifecycle nested-route rule — a body employee id next to a URL
 * one is two answers to one question). Positivity is the service's call
 * (Money refuses zero there with the priced-positive message); this answers
 * shape only.
 */
class SalaryAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'structure_id' => ['required', 'integer', 'exists:salary_structures,id'],
            'ctc_annual' => ['required', 'numeric'],
            'effective_from' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'structure_id.exists' => 'That salary structure does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
