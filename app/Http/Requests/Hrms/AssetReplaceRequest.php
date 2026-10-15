<?php

namespace App\Http\Requests\Hrms;

use App\Services\Hrms\Asset\AssetReplacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asset/HRMS — recording that one asset replaced another.
 */
class AssetReplaceRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'replacement_asset_id' => ['required', 'integer', 'exists:assets,id'],
            'reason' => ['required', Rule::in(AssetReplacement::REASONS)],
            'assign_to_holder' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the asset policy.
        return true;
    }
}
