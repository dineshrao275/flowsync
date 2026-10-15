<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Expense/HRMS — deciding a submitted claim.
 *
 * The verdict names the path; the service enforces the rest (resolved
 * chain, no self-approval, reasons for cuts and rejections), so this
 * answers shape only — including the conditional requirements the service
 * would otherwise 422 with a blunter message.
 */
class ExpenseDecideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'verdict' => ['required', Rule::in(['approve', 'reject'])],
            'approved_amount' => ['sometimes', 'nullable', 'numeric'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
