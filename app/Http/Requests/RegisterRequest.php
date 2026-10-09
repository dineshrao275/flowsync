<?php

namespace App\Http\Requests;

use App\Services\Tenancy\TenantIntake;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-registration asks for exactly what the Super Admin wizard asks for
 * (TenantIntake), with the registrant standing in as contact, billing contact
 * and default user — so a tenant has the same required data either way.
 */
class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        $intake = app(TenantIntake::class);
        $business = $intake->rules('business');
        $plan = $intake->rules('plan');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'business_name' => $business['name'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'industry' => $business['industry'],
            'company_size' => $business['company_size'],
            'country' => $business['country'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'start_trial' => $plan['start_trial'],
            'payment_method' => $plan['payment_method'],
        ];
    }
}
