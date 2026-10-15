<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Expense/HRMS — replacing a draft's lines wholesale.
 *
 * The same line rules as filing, without the claim header: the claim
 * travels in the URL (nested-route rule), and the service refuses anything
 * past draft before these rules even matter.
 */
class ExpenseClaimItemsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
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
            'items.*.category_id.exists' => 'A line names a category that does not exist.',
            'items.*.receipt_document_id.exists' => 'A line names a file that does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
