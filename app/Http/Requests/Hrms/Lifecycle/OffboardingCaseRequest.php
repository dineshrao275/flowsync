<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use App\Enums\Hrms\OffboardingReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — opening an exit run.
 *
 * The last working day is the fact the whole case keys off — the checklist
 * due dates and the employee’s exit date both derive from it — so it is
 * required, while the notice length stays optional: not every exit has one.
 */
class OffboardingCaseRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'last_working_day' => ['required', 'date'],
            'reason' => ['required', Rule::enum(OffboardingReason::class)],
            'notice_period_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
