<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — starting an onboarding case.
 *
 * Two ids and nothing else: the employee to onboard and the template to run.
 * Whether the employee already has a case, and whether the template is still
 * active, are the service’s call — a second copy of those rules here is a
 * second thing to forget when they change.
 */
class OnboardingCaseRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'template_id' => ['required', 'integer', 'exists:onboarding_templates,id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
