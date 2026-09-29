<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Statutory/HRMS — running one employee's annual TDS picture.
 *
 * Two fields, both load-bearing: the employee selects the person, the year
 * selects the fiscal year labelled by its starting calendar year. Past
 * years re-project (estimates refresh, surrenders stay); a year with no
 * payslip refuses in the service, not here.
 */
class TdsProjectionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'fiscal_year' => ['required', 'integer', 'min:1900', 'max:2100'],
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
