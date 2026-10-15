<?php

namespace App\Http\Requests\Hrms\Holiday;

use App\Enums\Hrms\OptionalHolidayStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Holiday/HRMS — answering a restricted holiday.
 *
 * An upsert by shape (the service keys on the employee/holiday pair), so
 * there is no update verb: declaring again replaces the answer, and the
 * audit row records each declaration.
 */
class HolidayOptionalRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'holiday_id' => ['required', 'integer', 'exists:holidays,id'],
            'status' => ['required', Rule::enum(OptionalHolidayStatus::class)],
            'taken_date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes `create` for the target employee; this
        // is only the framework's pre-check.
        return true;
    }
}
