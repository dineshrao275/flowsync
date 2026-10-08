<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — scheduling or holding a 1:1.
 *
 * One request for both verbs: POST requires the two sides and a time, PUT
 * patches whatever arrives (notes, follow-up, status). Either side may be
 * filed by a participant or manage — the policy, not this shape, answers
 * who sits in the room.
 */
class OneOnOneRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'employee_id' => [$required, 'integer', 'exists:employees,id'],
            'manager_employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'scheduled_at' => [$required, 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'follow_up' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'action_items' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(['scheduled', 'held', 'cancelled'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
            'manager_employee_id.exists' => 'That manager does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
