<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Statutory/HRMS — creating or editing a jurisdiction rulebook.
 *
 * One request for both verbs: POST requires identity, PUT patches whatever
 * arrives — except `code` and `country`, which are create-only. A rulebook
 * versions by replacement (new code), never by editing under snapshots
 * that already priced from it... and since snapshots never re-read, the
 * immutability is about the reader's trust, not the database's.
 */
class StatutoryConfigurationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isPost = $this->isMethod('POST');

        return [
            'country' => $isPost
                ? ['required', 'string', 'size:2']
                : ['sometimes', 'prohibited'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => [
                Rule::requiredIf(fn (): bool => $isPost),
                'string',
                'max:255',
            ],
            'code' => $isPost
                ? ['required', 'string', 'max:64', Rule::unique('statutory_configurations', 'code')]
                : ['sometimes', 'prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
