<?php

namespace App\Http\Requests\Hrms\CompOff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CompOff/HRMS — tuning the accrual policy.
 *
 * The `comp_off` section of the settings singleton, merged — a PUT that
 * blanked unmentioned keys would be a reset disguised as an edit (the P5.3
 * settings lesson). Zero months means credits never expire; the service
 * reads that as a null expiry, not as instant death.
 */
class CompOffSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from_weekends' => ['sometimes', 'boolean'],
            'from_holidays' => ['sometimes', 'boolean'],
            'validity_months' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
        ];
    }

    public function authorize(): bool
    {
        // The route gates on `hrms.comp_off.manage`; this is only the
        // framework's pre-check.
        return true;
    }
}
