<?php

namespace App\Http\Requests\Hrms\CompOff;

use App\Enums\Hrms\CompOffSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CompOff/HRMS — a manual grant of banked time.
 *
 * HR-entered by design (the policy gates creation on manage): the target
 * is explicit because nobody banks their own time, and the date cannot be
 * in the future because rest is banked after it is worked, not before.
 */
class CompOffCreditRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'source' => ['sometimes', Rule::enum(CompOffSource::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes `create` on the policy (manage-only).
        return true;
    }
}
