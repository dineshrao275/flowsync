<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantSubscriptionProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['tms' => ['present', 'nullable', 'boolean'], 'hrms' => ['present', 'nullable', 'boolean']];
    }
}
