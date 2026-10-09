<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImpersonationStartRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'tenant_id' => [
                'nullable',
                'integer',
                Rule::exists(Tenant::class, 'id'),
            ],
            // Why a super admin is entering a tenant: stored on the log and
            // shown in the audit feed. Required, so "just looking" is a decision.
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            // Read-only unless the operator explicitly asks to make changes.
            'mode' => ['sometimes', 'string', Rule::in(['read_only', 'write'])],
        ];
    }
}
