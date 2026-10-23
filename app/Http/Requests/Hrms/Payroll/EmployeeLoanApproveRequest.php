<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeLoanApproveRequest extends FormRequest
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
