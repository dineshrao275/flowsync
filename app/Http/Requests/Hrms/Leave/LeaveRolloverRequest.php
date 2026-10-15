<?php

namespace App\Http\Requests\Hrms\Leave;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Leave/HRMS — year-end carry-forward / lapse for a closed leave year.
 * `dry_run` reports the figures without writing.
 */
class LeaveRolloverRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'exists:leave_types,id'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
