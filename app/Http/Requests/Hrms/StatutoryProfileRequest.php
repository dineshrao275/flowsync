<?php

declare(strict_types=1);

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
            'pan' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'aadhaar' => ['sometimes', 'nullable', 'digits:12'],
            'uan' => ['sometimes', 'nullable', 'digits:12'],
            'esi_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pf_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pt_state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lwf_registration' => ['sometimes', 'boolean'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank_ifsc' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'tax_declaration' => ['sometimes', 'nullable', 'array'],
            'declarations' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * PAN and IFSC are case-insensitive identifiers; store one canonical form so the
     * format rule and every later comparison see the same spelling.
     */
    protected function prepareForValidation(): void
    {
        foreach (['pan', 'bank_ifsc'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }
}
