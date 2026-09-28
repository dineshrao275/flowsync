<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use App\Enums\Hrms\TaskOwnerScope;
use App\Enums\Hrms\TemplateTaskCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lifecycle/HRMS — adding or editing one template item.
 *
 * A bad `category` or `owner_scope` must 422 here, not reach an enum cast
 * and 500: both are raw strings off the wire until this rule says otherwise.
 */
class OnboardingTemplateTaskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', Rule::enum(TemplateTaskCategory::class)],
            'owner_scope' => ['sometimes', 'nullable', Rule::enum(TaskOwnerScope::class)],
            'due_offset_days' => ['sometimes', 'nullable', 'integer', 'min:-365', 'max:365'],
            'is_mandatory' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_mandatory')) {
            $value = $this->input('is_mandatory');
            $filtered = is_string($value) || is_int($value)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : $value;

            $this->merge(['is_mandatory' => $filtered ?? $value]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }
}
