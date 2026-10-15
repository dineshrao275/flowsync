<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Survey/HRMS — scheduling a campaign.
 *
 * Create-only: campaigns are never edited, only transitioned — a window
 * that moved under priced answers would rewrite what every response was
 * measured against. The threshold rides along (defaulting server-side),
 * because a campaign's promise must not move with later template edits.
 */
class SurveyCampaignRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'exists:survey_templates,id'],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'anonymity_threshold' => ['sometimes', 'integer', 'min:1'],
            'notify_on_publish' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_id.exists' => 'That template does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
