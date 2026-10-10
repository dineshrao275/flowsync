<?php

namespace App\Http\Requests\Hrms\Approval;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval/HRMS — handing approvals to someone for a window. `from_user_id`
 * is optional: omitted means "my own", and naming another person is a
 * manage-only act the policy decides.
 */
class ApprovalDelegationRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from_user_id' => ['nullable', 'integer'],
            'to_user_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
