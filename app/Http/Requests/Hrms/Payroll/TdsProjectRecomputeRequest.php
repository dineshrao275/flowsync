<?php

namespace App\Http\Requests\Hrms\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class TdsProjectRecomputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'run_id' => ['required', 'integer', 'exists:payroll_runs,id'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
