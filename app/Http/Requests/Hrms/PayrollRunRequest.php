<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payroll/HRMS — opening a pay run.
 *
 * Create-only: runs are never edited, only transitioned, so every field is
 * required and there is no PUT twin. The month-uniqueness refusal lives in
 * the service (a race between check and insert belongs in one transaction,
 * and the unique index backstops it either way).
 */
class PayrollRunRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'period_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'pay_period_start' => ['required', 'date'],
            'pay_period_end' => ['required', 'date', 'after_or_equal:pay_period_start'],
            'pay_date' => ['required', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
