<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CustomFields\CustomFieldService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomFieldIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entity' => ['required', Rule::in(CustomFieldService::ENTITIES)],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }
}
