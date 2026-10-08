<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Expense/HRMS — creating or editing a claim category.
 *
 * One request for both verbs: POST requires the name, PUT patches whatever
 * arrives. `slug` and `is_system` are absent on purpose — the slug is
 * server-allocated, and hand-made rows are never starters.
 */
class ExpenseCategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requires_receipt_above' => ['sometimes', 'nullable', 'numeric'],
            'is_reimbursable' => ['sometimes', 'boolean'],
            'payroll_component_id' => ['sometimes', 'nullable', 'integer', 'exists:salary_components,id'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payroll_component_id.exists' => 'That pay head does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
