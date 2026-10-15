<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leave/HRMS — raising a statutory exemption ask.
 *
 * HR-entered by design (the jurisdiction gate lands in P6.4's policy
 * layer): `days` is stated, not derived, because an exemption spans
 * statutory calendars, not rostered workdays.
 */
class LeaveExemptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'fiscal_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
