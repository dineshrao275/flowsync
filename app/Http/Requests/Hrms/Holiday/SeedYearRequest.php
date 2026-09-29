<?php

namespace App\Http\Requests\Hrms\Holiday;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Holiday/HRMS — expanding a config year on demand.
 */
class SeedYearRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    public function authorize(): bool
    {
        // The route gates on `hrms.holidays.manage`; this is only the
        // framework's pre-check.
        return true;
    }
}
