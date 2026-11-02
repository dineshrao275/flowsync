<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Expense/HRMS — moving a claim's approval step. Shape only: whether the caller may act, the
 * override reason and the rejection reason are the engine's and the service's rules.
 */
class ExpenseStepRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
