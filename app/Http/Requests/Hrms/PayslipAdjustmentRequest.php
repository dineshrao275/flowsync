<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payroll/HRMS — hand-adding a bonus or recovery line.
 *
 * The payslip travels in the URL (the nested-route rule); the `kind` enum
 * is validated here so the service never sees a third kind to misfile.
 * Amount positivity is the service's call — Money refuses zero there with
 * the priced-positive message — so this answers shape only.
 */
class PayslipAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['earning', 'deduction'])],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
            'component_id' => ['sometimes', 'nullable', 'integer', 'exists:salary_components,id'],
            'reference_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'component_id.exists' => 'That head does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
