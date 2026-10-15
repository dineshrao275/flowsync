<?php

namespace App\Http\Requests\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Employee/HRMS — moving many people to one employment status. `dry_run`
 * previews the per-person outcome without writing anything.
 */
class EmployeeBulkStatusRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'to' => ['required', Rule::enum(EmployeeStatus::class)],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
