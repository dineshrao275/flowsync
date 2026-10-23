<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FeatureFlag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeatureFlagStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique(FeatureFlag::class, 'key')],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'rollout_percent' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
