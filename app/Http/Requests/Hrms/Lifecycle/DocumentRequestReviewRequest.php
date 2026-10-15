<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — answering an ask with waive or reject.
 *
 * The reason is required for both: a waived ask without one reads as
 * forgotten, and a rejected file without one leaves the employee guessing
 * what to fix. Accept takes no body and uses no request class — there is
 * nothing to validate about “yes”.
 */
class DocumentRequestReviewRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
