<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Expense/HRMS — filing a claim with its lines.
 *
 * Totals never travel (the service sums the lines); the employee travels
 * in the body on this flat route, and the policy answers self-or-manage
 * for whoever it names. Line rules mirror the service's — shape here,
 * truth there.
 */
class ExpenseClaimRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'claim_date' => ['required', 'date'],
            'period_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'purpose' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.category_id' => ['sometimes', 'nullable', 'integer', 'exists:expense_categories,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric'],
            'items.*.spent_at' => ['sometimes', 'nullable', 'date'],
            'items.*.vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.receipt_document_id' => ['sometimes', 'nullable', 'integer', 'exists:employee_documents,id'],
            'items.*.is_billable' => ['sometimes', 'boolean'],
            'items.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
            'items.*.category_id.exists' => 'A line names a category that does not exist.',
            'items.*.receipt_document_id.exists' => 'A line names a file that does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
