<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentMineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type_id' => ['sometimes', 'nullable', 'integer', 'exists:document_types,id'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
