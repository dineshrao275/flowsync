<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Statutory/HRMS — filing an exemption claim.
 *
 * The claim is filed flat (`POST .../declarations`) with the employee in
 * the body — the plan's route — so `employee_id` is required here, and the
 * policy answers self-or-manage for it.
 */
class StatutoryDeclarationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'fiscal_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'section' => ['required', 'string', 'max:32'],
            'declared_amount' => ['required', 'numeric'],
            'proof_document_id' => ['sometimes', 'nullable', 'integer', 'exists:employee_documents,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
            'proof_document_id.exists' => 'That file does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
