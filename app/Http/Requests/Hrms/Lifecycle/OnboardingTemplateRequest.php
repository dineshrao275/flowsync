<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use App\Enums\Hrms\TaskOwnerScope;
use App\Enums\Hrms\TemplateTaskCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — creating or editing an onboarding template.
 *
 * One request for both verbs: the difference is a single line (`name`), and
 * a `sometimes` ahead of a conditional requirement is how that line gets
 * skipped. Items have their own request — folding them in here would let a
 * rename silently rewrite the checklist it only meant to retitle.
 */
class OnboardingTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            // Nested items on create: without these rules `validated()`
            // silently drops the whole checklist, and the template arrives
            // empty — a template that “saved” with no items is a form that
            // lied about succeeding.
            'tasks' => ['sometimes', 'array', 'max:100'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tasks.*.category' => ['sometimes', 'nullable', Rule::enum(TemplateTaskCategory::class)],
            'tasks.*.owner_scope' => ['sometimes', 'nullable', Rule::enum(TaskOwnerScope::class)],
            'tasks.*.due_offset_days' => ['sometimes', 'nullable', 'integer', 'min:-365', 'max:365'],
            'tasks.*.is_mandatory' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $value = $this->input('is_active');
            $filtered = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : $value;

            $this->merge(['is_active' => $filtered ?? $value]);
        }
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework’s pre-check.
        return true;
    }
}
