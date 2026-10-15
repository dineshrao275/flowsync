<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leave/HRMS — a manager's signed correction to one balance. The reason is
 * mandatory: an unexplained ledger row is exactly what an audit asks about.
 */
class LeaveAdjustRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quantity' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        // The route gates on `hrms.leave.manage`.
        return true;
    }
}
