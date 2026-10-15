<?php

namespace App\Http\Requests\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Holiday/HRMS — creating or editing a holiday.
 *
 * One request for both verbs. `calendar_id` rides the URL for nested
 * creation, never the body — the body names the row, the URL names its
 * home, and the two together cannot disagree about where it lands.
 */
class HolidayRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'string', 'max:255'],
            'date' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'date'],
            'type' => ['sometimes', Rule::enum(HolidayType::class)],
            'is_recurring' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy (nested creation
        // against the parent calendar); this is only the pre-check.
        return true;
    }
}
