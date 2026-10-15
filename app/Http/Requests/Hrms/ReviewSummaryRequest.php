<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — filing or editing a review write-up.
 *
 * One request for both verbs: POST requires the employee, PUT patches
 * whatever arrives. Ratings ride 1–5 per dimension — there is no overall
 * score field, and none may be smuggled through here. Visibility defaults
 * to hidden: a write-up is released, never leaked.
 */
class ReviewSummaryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'employee_id' => [$required, 'integer', 'exists:employees,id'],
            'self_rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'manager_rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'strengths' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'improvements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'manager_comments' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'visibility_to_employee' => ['sometimes', Rule::in(['hidden', 'shared'])],
            'status' => ['sometimes', Rule::in(['draft', 'calibrating', 'final', 'acknowledged'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
