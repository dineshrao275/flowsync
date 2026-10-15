<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — answering a feedback ask.
 *
 * The ask travels in the URL; the answer is an action plus an optional
 * rating and body. A submit without a 1–5 rating is refused here rather
 * than deep in the service — the shape violation is visible at the edge,
 * while the service still owns decided-ask refusals.
 */
class FeedbackRespondRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['submit', 'decline'])],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
