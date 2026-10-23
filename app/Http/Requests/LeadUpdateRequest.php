<?php

namespace App\Http\Requests;

use App\Models\MarketingLead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                MarketingLead::STATUS_NEW,
                MarketingLead::STATUS_CONTACTED,
                MarketingLead::STATUS_QUALIFIED,
                MarketingLead::STATUS_WON,
                MarketingLead::STATUS_LOST,
            ])],
        ];
    }
}
