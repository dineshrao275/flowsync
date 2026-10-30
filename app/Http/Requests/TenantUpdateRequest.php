<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ValidatesResourceLimits;
use Illuminate\Foundation\Http\FormRequest;

class TenantUpdateRequest extends FormRequest
{
    use ValidatesResourceLimits;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id ?? $this->route('tenant');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug,'.$tenantId],
            'description' => ['nullable', 'string', 'max:255'],
            'limits_override' => ['nullable', 'array'],
            ...$this->resourceLimitRules('limits_override'),
        ];
    }
}
