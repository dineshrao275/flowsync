<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantSubscriptionAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'auto_renew' => ['nullable', 'boolean'],
            'billing_provider' => ['nullable', 'string', 'max:64'],
            'billing_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
