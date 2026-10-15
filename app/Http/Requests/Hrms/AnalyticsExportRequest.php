<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Analytics/HRMS — exporting one dashboard domain as CSV.
 *
 * Validates the HTTP shape only: a known domain plus the shared window and
 * scope filters (org ids against the tenant's own tables, so a foreign id
 * 422s instead of silently scoping to nothing — the P18.3 rule). Whether
 * the caller may read the domain is the controller's call, because the
 * permission differs per domain and lives next to the read it guards.
 */
class AnalyticsExportRequest extends FormRequest
{
    /**
     * The dashboard tabs with a CSV form, mirroring the tab endpoints —
     * never `overview` (tiles, not rows) and never anything the readers
     * cannot build.
     *
     * @var list<string>
     */
    public const DOMAINS = [
        'attendance',
        'leave',
        'lifecycle',
        'performance',
        'payroll',
        'documents',
        'assets',
    ];

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
            'domain' => ['required', 'string', Rule::in(self::DOMAINS)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'employment_type_id' => ['sometimes', 'nullable', 'integer', 'exists:employment_types,id'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_categories,id'],
        ];
    }

    /**
     * The reader scope: everything except the domain itself, nulls dropped.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();
        unset($filters['domain']);

        return array_filter($filters, fn ($value) => $value !== null);
    }
}
