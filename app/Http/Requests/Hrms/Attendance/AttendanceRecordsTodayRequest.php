<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceRecordsTodayRequest extends FormRequest
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
        ];
    }
}
