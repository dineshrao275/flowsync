<?php

namespace App\Http\Requests\Hrms\Employee;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeChangeApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
