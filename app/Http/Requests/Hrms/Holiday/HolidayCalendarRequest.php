<?php

namespace App\Http\Requests\Hrms\Holiday;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Holiday/HRMS — creating or editing a calendar.
 *
 * One request for both verbs (the DepartmentRequest precedent):
 * `Rule::requiredIf` on POST, plain `sometimes` on PUT. `slug` is absent
 * on purpose — server-allocated with an auto-suffix, so a client rename
 * can never collide with the string the seeder joins against.
 */
class HolidayCalendarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [Rule::requiredIf(fn (): bool => $this->isMethod('POST')), 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'region' => ['sometimes', 'nullable', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
