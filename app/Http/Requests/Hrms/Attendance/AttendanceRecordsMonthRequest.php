<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceRecordsMonthRequest extends FormRequest
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
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
