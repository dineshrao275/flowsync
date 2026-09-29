<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Statutory/HRMS — filing one person's identifiers.
 *
 * Shape only: the service decides what is stored (aadhaar arrives full and
 * survives as four digits; the account arrives clear and survives
 * encrypted). `employee_id` travels in the URL — a body id next to a URL
 * id is two answers to one question.
 */
class StatutoryProfileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'pan' => ['sometimes', 'nullable', 'string', 'max:20'],
            'aadhaar' => ['sometimes', 'nullable', 'digits:12'],
            'uan' => ['sometimes', 'nullable', 'string', 'max:20'],
            'esi_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pf_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pt_state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lwf_registration' => ['sometimes', 'boolean'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank_ifsc' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tax_declaration' => ['sometimes', 'nullable', 'array'],
            'declarations' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
