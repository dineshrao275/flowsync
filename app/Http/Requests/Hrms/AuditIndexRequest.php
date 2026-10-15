<?php

namespace App\Http\Requests\Hrms;

use App\Services\Hrms\Audit\AuditLogQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Audit/HRMS — listing the HRMS audit trail.
 *
 * Validates the HTTP shape only. The sort whitelist lives in
 * {@see AuditLogQuery} next to the `ORDER BY` that
 * consumes it (the directory-query precedent): an unknown sort falls back
 * to newest-first instead of 422ing a bookmarked URL.
 */
class AuditIndexRequest extends FormRequest
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
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
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
