<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Leave;

use App\Enums\Hrms\LeaveHalf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveRequestAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'from_half' => ['sometimes', 'nullable', Rule::enum(LeaveHalf::class)],
            'to_half' => ['sometimes', 'nullable', Rule::enum(LeaveHalf::class)],
        ];
    }
}
