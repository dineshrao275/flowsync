<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — attaching the submitted file to an ask.
 *
 * One id: the file. Whether it belongs to the ask’s employee is the
 * service’s call, where the employee link lives — a rule duplicated here
 * would need the same query twice.
 */
class DocumentRequestSubmitRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_id' => ['required', 'integer', 'exists:employee_documents,id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
