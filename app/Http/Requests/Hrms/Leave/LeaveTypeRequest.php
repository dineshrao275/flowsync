<?php

namespace App\Http\Requests\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — creating or editing a leave type.
 *
 * One request for both verbs (the DepartmentRequest precedent):
 * `Rule::requiredIf` on POST, plain `sometimes` on PUT. `slug` is absent on
 * purpose — server-allocated with an auto-suffix, so a client rename can
 * never collide with the string payroll joins against. `is_system` is
 * absent too: no payload promotes a row into the protected set.
 */
class LeaveTypeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_paid' => ['sometimes', 'boolean'],
            'accrual_method' => ['sometimes', Rule::enum(LeaveAccrualMethod::class)],
            'accrual_rate' => ['sometimes', 'numeric', 'min:0', 'max:365'],
            'max_balance' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'carry_forward' => ['sometimes', 'boolean'],
            'carry_forward_cap' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            'encashable' => ['sometimes', 'boolean'],
            'requires_document_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'min_days_per_request' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365'],
            'max_days_per_year' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'allow_negative_balance' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'The color must be a hex value like #059669.',
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
