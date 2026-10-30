<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeatureFlagUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
