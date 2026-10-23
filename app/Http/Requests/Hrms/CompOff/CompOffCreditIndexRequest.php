<?php

namespace App\Http\Requests\Hrms\CompOff;

use Illuminate\Foundation\Http\FormRequest;

class CompOffCreditIndexRequest extends FormRequest
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
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
