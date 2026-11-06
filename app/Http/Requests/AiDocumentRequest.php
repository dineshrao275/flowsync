<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Ai\Documents\AiDocumentExtractor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class AiDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', File::types(AiDocumentExtractor::EXTENSIONS)->max((int) config('ai.document_max_kb'))],
            'project_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
