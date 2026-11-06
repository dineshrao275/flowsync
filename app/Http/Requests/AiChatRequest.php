<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.(int) config('ai.max_input_chars')],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'stream' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'array'],
            'context.route' => ['nullable', 'string', 'max:120'],
            'context.product' => ['nullable', 'string', 'in:tms,hrms'],
        ];
    }
}
