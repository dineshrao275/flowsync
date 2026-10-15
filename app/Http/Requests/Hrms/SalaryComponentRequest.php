<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\CalculationType;
use App\Enums\Hrms\SalaryComponentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Compensation/HRMS — creating or editing a pay head.
 *
 * One request for both verbs (the DepartmentRequest idiom): POST requires
 * the identity fields, PUT patches whatever arrives. `slug`, `is_system`
 * and `is_statutory` are absent on purpose — the slug is server-allocated,
 * and hand-made rows are never system or statutory.
 */
class SalaryComponentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'name' => [$required, 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'type' => [$required, Rule::enum(SalaryComponentType::class)],
            'calculation_type' => ['sometimes', Rule::enum(CalculationType::class)],
            'default_value' => ['sometimes', 'numeric'],
            'is_taxable' => ['sometimes', 'boolean'],
            'is_prorated' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
