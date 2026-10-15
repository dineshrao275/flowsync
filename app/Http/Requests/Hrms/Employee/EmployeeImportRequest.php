<?php

namespace App\Http\Requests\Hrms\Employee;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Employee/HRMS — a CSV upload for bulk hire (preview or commit).
 */
class EmployeeImportRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
            'skip_invalid' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
