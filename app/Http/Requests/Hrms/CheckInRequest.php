<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — filing a check-in note.
 *
 * The cycle travels in the URL on this nested route; the employee travels
 * in the body, and the policy answers self-or-manage for whoever it names.
 */
class CheckInRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'body' => ['required', 'string', 'max:5000'],
            'mood' => ['sometimes', 'nullable', Rule::in(['great', 'good', 'ok', 'low'])],
            'blockers' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'needs_support' => ['sometimes', 'boolean'],
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
