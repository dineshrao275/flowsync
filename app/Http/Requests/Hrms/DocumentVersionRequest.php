<?php

namespace App\Http\Requests\Hrms;

use App\Services\Hrms\Document\DocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Document/HRMS — replacing a document with a new version. Only the bytes and
 * the new dates are the caller's to give; title, type, visibility and
 * confidentiality carry over from the row being replaced.
 */
class DocumentVersionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(DocumentUpload::ALLOWED_MIMES)->max(DocumentUpload::MAX_KILOBYTES)],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
