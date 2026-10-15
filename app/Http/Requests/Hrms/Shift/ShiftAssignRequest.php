<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift/HRMS — pointing employees' default shift at a catalogue shift
 * (or clearing it with a null `shift_id` on the clear route).
 */
class ShiftAssignRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
