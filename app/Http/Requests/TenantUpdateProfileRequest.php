<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantUpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'company_size' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'billing_currency' => ['nullable', 'string', 'max:3'],
            'timezone' => ['nullable', 'string', 'max:128'],
            'locale' => ['nullable', 'string', 'max:16'],
            'brand_domain' => ['nullable', 'string', 'max:255'],
            'brand_logo_url' => ['nullable', 'url', 'max:255'],
            'brand_primary_color' => ['nullable', 'string', 'max:9'],
        ];
    }
}
