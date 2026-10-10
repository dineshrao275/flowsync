<?php

namespace App\Http\Requests\Hrms\Approval;

use App\Enums\Hrms\ApprovalStepMode;
use App\Enums\Hrms\ApproverType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Approval/HRMS — saving a domain's chain. Shape only; whether the role, user
 * and condition field exist for that domain is `ChainTemplates::normalize()`'s
 * call (one definition, also used when a chain is reset).
 */
class ApprovalChainRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:8'],
            'steps.*.type' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.role_slug' => ['nullable', 'string', 'max:100'],
            'steps.*.permission' => ['nullable', 'string', 'max:150'],
            'steps.*.user_id' => ['nullable', 'integer'],
            'steps.*.omit_if_requester_holds' => ['nullable', 'boolean'],
            'steps.*.stage' => ['nullable', 'integer', 'min:1', 'max:20'],
            'steps.*.mode' => ['nullable', Rule::enum(ApprovalStepMode::class)],
            'steps.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'steps.*.when' => ['nullable', 'array'],
            'steps.*.when.field' => ['required_with:steps.*.when', 'string', 'max:40'],
            'steps.*.when.op' => ['required_with:steps.*.when', 'string', 'max:3'],
            'steps.*.when.value' => ['required_with:steps.*.when', 'numeric'],
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'reminder_before_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'escalation_role_slug' => ['nullable', 'string', 'max:100'],
        ];
    }
}
