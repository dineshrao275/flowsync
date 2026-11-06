<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiProposalStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'document_id' => ['nullable', 'integer', 'min:1'],
            'text' => ['nullable', 'string', 'max:'.(int) config('ai.document_max_chars')],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
