<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Employee/HRMS — the directory query string.
 *
 * A request class rather than inline `$request->validate()` because this is the
 * one place the sort column and the filter columns are trusted: every one of
 * them is a raw string off the query string, and the sort reaches an ORDER BY.
 */
class EmployeeIndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(EmployeeStatus::class)],
            // Enum, not `string`: the query casts this with WorkMode::from(),
            // so an unvalidated value is a 500 rather than a 422. The catalog is
            // served from the same enum in filterOptions(), so the two agree by
            // construction and cannot drift.
            'work_mode' => ['sometimes', 'nullable', Rule::enum(WorkMode::class)],
            'employment_type_id' => ['sometimes', 'nullable', 'integer', 'exists:employment_types,id'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            // P3.3's org filters, an exact match each — see the note in
            // EmployeeDirectoryQuery::applyRelations().
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'designation_id' => ['sometimes', 'nullable', 'integer', 'exists:designations,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'joined_from' => ['sometimes', 'nullable', 'date'],
            'joined_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:joined_from'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:32'],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The validated filters, as the directory query wants them.
     *
     * `sort` is passed through as-is because `EmployeeDirectoryQuery` holds the
     * sortable-column whitelist — a whitelist that lived in a FormRequest would
     * be invisible to the query it protects. `per_page` comes along for the same
     * reason: the query clamps it, so a second reader of it here would be a
     * second default to keep in step.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn (mixed $value) => $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
