<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — creating or editing a blackout window. Null type/department
 * mean "not narrowed".
 */
class LeaveBlackoutRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'name' => [Rule::requiredIf($create), 'string', 'max:255'],
            'from_date' => [Rule::requiredIf($create), 'date'],
            'to_date' => [Rule::requiredIf($create), 'date', 'after_or_equal:from_date'],
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'exists:leave_types,id'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
