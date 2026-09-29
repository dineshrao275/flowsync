<?php

namespace App\Http\Requests\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — creating or editing a leave policy.
 *
 * One request for both verbs (the DepartmentRequest precedent). `is_default`
 * is accepted on either verb: promoting a policy demotes the previous
 * default inside the service transaction, so the tenant never holds two.
 */
class LeavePolicyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'string', 'max:255'],
            'accrual_period' => ['sometimes', Rule::enum(LeaveAccrualPeriod::class)],
            'start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'carry_forward_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'max_carry_forward' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'negative_balance_allowed' => ['sometimes', 'boolean'],
            'max_negative_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
