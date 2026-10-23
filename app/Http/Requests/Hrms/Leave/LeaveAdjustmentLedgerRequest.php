<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;

class LeaveAdjustmentLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'exists:leave_types,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
