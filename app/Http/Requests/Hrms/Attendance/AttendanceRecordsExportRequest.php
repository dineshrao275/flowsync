<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceRecordsExportRequest extends FormRequest
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
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ];
    }
}
