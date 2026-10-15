<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leave/HRMS — running the accrual for a type.
 *
 * Scope, not rows: `leave_type_id` is required, everything else narrows
 * (one employee, one year, one date). The service defaults the year to the
 * date's leave year and the date to today, and refuses a date outside its
 * year — a silent default here would credit the wrong ledger.
 */
class LeaveAccrueRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'as_of' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        // The route gates on `hrms.leave.manage`; this is only the
        // framework's pre-check.
        return true;
    }
}
