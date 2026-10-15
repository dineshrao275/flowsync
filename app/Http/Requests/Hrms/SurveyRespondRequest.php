<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Survey/HRMS — answering a campaign.
 *
 * The campaign travels in the URL; the answers ride as question/value
 * pairs. Identity travels nowhere the client controls for anonymous
 * campaigns — the fingerprint derives server-side from the observed IP
 * and user agent. Values are shape-checked here and priced nowhere near
 * here: the service maps types to columns and enforces required plus
 * bounds.
 */
class SurveyRespondRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'exists:survey_questions,id'],
            'answers.*.value' => ['sometimes', 'nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.*.question_id.exists' => 'An answer names a question that does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
