<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiProposalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('ai.proposal_max_items')],
            'items.*.id' => ['nullable', 'string', 'max:40'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:6000'],
            'items.*.kind' => ['nullable', 'string'],
            'items.*.priority' => ['nullable', 'string', 'max:40'],
            'items.*.story_points' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'items.*.selected' => ['nullable', 'boolean'],
        ];
    }
}
