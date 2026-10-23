<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueTypeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_subtask' => ['nullable', 'boolean'],
            'hierarchy_level' => ['nullable', 'integer', 'between:0,2'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
