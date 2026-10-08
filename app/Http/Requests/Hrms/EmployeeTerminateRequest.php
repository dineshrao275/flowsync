<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Employee/HRMS — ending an employment.
 *
 * No `to` and no `effective_date`: termination has one target and happens now,
 * so accepting either would only allow a caller to record a dismissal as a
 * resignation-exit on a chosen date, which is precisely the record a
 * termination-dispute audit exists to catch.
 *
 * `reason` is required because an exit with no stated cause is not a usable
 * record — it is the first thing a regulator, a tribunal or a reference check
 * asks for.
 */
class EmployeeTerminateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
