<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — drafting or editing a goal.
 *
 * One request for both verbs: POST requires identity, PUT patches whatever
 * arrives. The cycle travels in the URL on creation (nested-route rule);
 * weights are shape-checked here and totalled in the workflow, which names
 * the unbalanced employee when they do not sum.
 */
class PerformanceGoalRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'employee_id' => [$required, 'integer', 'exists:employees,id'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'metric_type' => ['sometimes', Rule::in(['none', 'task_completion', 'worklog_hours', 'manual'])],
            'target_value' => ['sometimes', 'nullable', 'numeric'],
            'weight' => ['sometimes', 'numeric', 'min:0'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'achieved', 'missed', 'cancelled'])],
            'progress_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
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
