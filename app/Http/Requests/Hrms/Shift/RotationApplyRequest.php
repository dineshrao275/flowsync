<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift/HRMS — applying a rotation to people over a window. `offset` staggers
 * where in the cycle the window starts.
 */
class RotationApplyRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:27'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
