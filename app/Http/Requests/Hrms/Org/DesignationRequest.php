<?php

namespace App\Http\Requests\Hrms\Org;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Org/HRMS — creating or editing a designation.
 *
 * A designation may span departments, so `department_id` is optional rather than
 * implied: "Manager" is a band, not a place, and forcing every row to name a
 * department is what pushes people to invent a department for an org chart that
 * does not have one.
 */
class DesignationRequest extends FormRequest
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
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The band a payslip prints. Bounded rather than free, so a "level 0"
            // or a "level 900" cannot be entered and quietly sort wrongly forever.
            'level' => ['sometimes', 'nullable', 'integer', 'between:1,20'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
