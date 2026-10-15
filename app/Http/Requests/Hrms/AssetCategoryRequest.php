<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asset/HRMS — creating or editing a register catalogue row.
 *
 * One request for both verbs: POST requires the name, PUT patches whatever
 * arrives. `slug` and `is_system` are absent on purpose — the slug is
 * server-allocated, and hand-made rows are never starters.
 */
class AssetCategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_condition' => ['sometimes', 'nullable', Rule::in(['new', 'good', 'fair', 'poor'])],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
