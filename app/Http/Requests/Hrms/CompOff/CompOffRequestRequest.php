<?php

namespace App\Http\Requests\Hrms\CompOff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CompOff/HRMS — filing a redemption ask.
 *
 * The range prices in whole standard days (the service charges every
 * non-week-off date the same minutes), so there are no halves to validate —
 * halves belong to leave, where a day can be split, not to comp-off, where
 * a day is the unit.
 */
class CompOffRequestRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
