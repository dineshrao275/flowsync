<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Survey/HRMS — building or editing a questionnaire.
 *
 * One request for both verbs: POST requires identity, PUT patches whatever
 * arrives. Questions ride along on creation (the common case: a template
 * is born with its questions); the dedicated questions endpoint replaces
 * them wholesale afterwards. `slug` is server-allocated like every other
 * catalogue name.
 */
class SurveyTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->isMethod('POST'));

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['sometimes', Rule::in(['pulse', 'engagement', 'onboarding_exit', 'exit', 'custom'])],
            'is_anonymous' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'frequency' => ['sometimes', Rule::in(['one_time', 'weekly', 'monthly', 'quarterly', 'annual'])],
            'audience_scope' => ['sometimes', Rule::in(['all', 'department', 'role', 'location', 'explicit'])],
            'audience_meta' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'questions' => ['sometimes', 'array'],
            'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.type' => ['sometimes', Rule::in(['scale', 'text', 'multiple_choice', 'yes_no', 'nps'])],
            'questions.*.options' => ['sometimes', 'nullable', 'array'],
            'questions.*.is_required' => ['sometimes', 'boolean'],
            'questions.*.min' => ['sometimes', 'nullable', 'numeric'],
            'questions.*.max' => ['sometimes', 'nullable', 'numeric'],
            'questions.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
