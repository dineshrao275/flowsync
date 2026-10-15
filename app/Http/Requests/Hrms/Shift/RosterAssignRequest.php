<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift/HRMS — rostering one person onto a shift for a date range.
 *
 * `shift_id` may be null for a flexible/no-shift stretch. `weekly_offs` is
 * seven Monday-first 0/1 flags; omitted, the shift's working days decide.
 */
class RosterAssignRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'shift_id' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'weekly_offs' => ['nullable', 'array', 'size:7'],
            'weekly_offs.*' => ['integer', 'in:0,1'],
            'is_flexible' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
