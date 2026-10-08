<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — raising a document ask from the HTTP surface.
 *
 * Case-linked asks are raised by the case services themselves when they
 * materialise; this is the standalone “HR needs a file” path. The optional
 * case pair lets HR attach a free ask to a running case after the fact —
 * whether that case exists and is open is the service’s call, not a rule to
 * duplicate here.
 */
class DocumentRequestStoreRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'case_type' => ['sometimes', 'nullable', Rule::in(['onboarding', 'offboarding'])],
            'case_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
