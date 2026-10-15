<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Survey/HRMS — replacing a template's questions wholesale.
 *
 * One call names the full set (the structure-heads sync idiom): omitted
 * questions detach, and a payload cannot smuggle a question onto another
 * template. Each row needs its text; everything else rides optional.
 */
class SurveyQuestionsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'questions' => ['required', 'array', 'min:1'],
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
