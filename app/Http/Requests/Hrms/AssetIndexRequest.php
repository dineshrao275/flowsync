<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

class AssetIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_categories,id'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
