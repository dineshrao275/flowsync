<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\DataAccessAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Audit/HRMS — listing who read what.
 *
 * The sibling of {@see AuditIndexRequest} for the other ledger: salary,
 * bank, statutory and document reads plus every export. Same shape on
 * purpose, so the two viewer screens share one filter bar.
 */
class AuditDataAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'actor_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'record_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'action' => ['sometimes', 'nullable', Rule::enum(DataAccessAction::class)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn (mixed $value) => $value !== null,
        );
    }
}
