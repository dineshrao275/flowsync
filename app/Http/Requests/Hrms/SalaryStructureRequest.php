<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compensation/HRMS — creating or editing a salary template, plus its head
 * list sync.
 *
 * `slug` is server-allocated (the DepartmentRequest rule); `currency` and
 * `effective_from` are create-only — a template versions by effective date
 * instead of being edited under its assignments, so neither may move
 * afterwards. The `components` list is the full replacement set validated
 * here and synced by the catalog service, never merged.
 */
class SalaryStructureRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isPost = $this->isMethod('POST');

        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $isPost),
                'string',
                'max:255',
            ],
            'currency' => $isPost
                ? ['sometimes', 'string', 'size:3']
                : ['sometimes', 'prohibited'],
            'effective_from' => $isPost
                ? ['required', 'date']
                : ['sometimes', 'prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'components' => ['sometimes', 'array'],
            'components.*.component_id' => ['required', 'integer', 'exists:salary_components,id'],
            'components.*.value' => ['sometimes', 'numeric'],
            'components.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'components.*.component_id.exists' => 'A linked head does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
