<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P1.7 — super-admin per-tenant HRMS entitlement write.
 *
 * Accepts either shape the admin grid sends:
 *   {modules: ['hrms.payroll', ...]}  additive grant list (the module checklist)
 *   {enabled: true|false}             the single "HRMS on/off" switch
 *
 * Every module key is validated against the catalog so a typo 422s instead of
 * being persisted as an inert entitlement that reads as "granted but missing"
 * (plan P1.7).
 */
class TenantHrmsUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(config('subscriptions.modules', []))],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'modules.*.in' => 'Unknown module. It must exist in the module catalog.',
        ];
    }

    /**
     * At least one of the two inputs must be present, otherwise the request is
     * a silent no-op that still writes an audit row.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('modules') && ! $this->has('enabled')) {
                $validator->errors()->add('form', 'Provide either `modules` or `enabled`.');
            }
        });
    }
}
