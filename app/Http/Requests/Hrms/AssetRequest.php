<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asset/HRMS — entering or editing a register item.
 *
 * One request for both verbs: POST requires identity, PUT patches whatever
 * arrives. `asset_code`, assignment state and the ledger are absent on
 * purpose — codes stamp server-side, hands change through assign/return,
 * and no form edits either.
 */
class AssetRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'name' => [$required, 'string', 'max:255'],
            'category_id' => [$required, 'integer', 'exists:asset_categories,id'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'purchase_value' => ['sometimes', 'nullable', 'numeric'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'invoice_document_id' => ['sometimes', 'nullable', 'integer', 'exists:employee_documents,id'],
            'warranty_ends_at' => ['sometimes', 'nullable', 'date'],
            'condition' => ['sometimes', Rule::in(['new', 'good', 'fair', 'poor', 'damaged'])],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'That category does not exist.',
            'invoice_document_id.exists' => 'That file does not exist.',
            'location_id.exists' => 'That location does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
