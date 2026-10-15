<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\DocumentSource;
use App\Enums\Hrms\DocumentVisibility;
use App\Services\Hrms\Document\DocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Document/HRMS — filing one document.
 *
 * Validates the HTTP shape only: well-formed ids, a recognisable title, an
 * allowed file, and plausible metadata. Whether the type is still active and
 * whether the dates make sense together are {@see DocumentUpload}’s call,
 * because a second copy of those rules is a second thing to forget — and the
 * direct service callers (console, seeders, tests) never pass through here.
 */
class DocumentUploadRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'file' => [
                'required',
                File::types(DocumentUpload::ALLOWED_MIMES)->max(DocumentUpload::MAX_KILOBYTES),
            ],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'source' => ['sometimes', 'nullable', Rule::enum(DocumentSource::class)],
            'visibility' => ['sometimes', 'nullable', Rule::enum(DocumentVisibility::class)],
            'confidential' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Multipart forms deliver checkboxes as text; normalise before the
     * `boolean` rule sees them, and leave anything else alone so it still
     * 422s instead of silently becoming false.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('confidential')) {
            $value = $this->input('confidential');
            $filtered = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : $value;

            $this->merge(['confidential' => $filtered ?? $value]);
        }
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy with the resolved
        // employee; this is only the framework’s pre-check.
        return true;
    }
}
