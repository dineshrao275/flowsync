<?php

namespace App\Http\Requests\Hrms\CompOff;

use Illuminate\Foundation\Http\FormRequest;

class CompOffSettingsAccrueRequest extends FormRequest
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
