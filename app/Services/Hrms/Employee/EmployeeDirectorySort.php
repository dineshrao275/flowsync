<?php

namespace App\Services\Hrms\Employee;

/**
 * Employee/HRMS — the directory's ORDER BY, resolved from request input.
 *
 * Split out of `EmployeeDirectoryQuery` when P3.3's three org filters pushed
 * that class past the 300-line ceiling, but the split earns its place on its own
 * terms: the whitelist and the query that consumes it are the same decision.
 * A column allowed here and absent from the index, or a column sorted on here
 * and never selected, is a bug that no test of either half would catch.
 *
 * A whitelist rather than passing `$filters['sort']` into `orderBy`: that
 * parameter is user input, and an unvalidated column name is an injection
 * vector and a way to sort by `password`.
 */
final readonly class EmployeeDirectorySort
{
    /**
     * Columns the directory may be sorted by, as the request names them.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'name' => 'employees.name',
        'code' => 'employees.employee_code',
        'joining_date' => 'employees.joining_date',
        'status' => 'employees.status',
        'designation' => 'employees.designation',
        'created_at' => 'employees.created_at',
    ];

    private const DEFAULT = 'employees.name';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private array $filters) {}

    /**
     * The ORDER BY column, or the default when the request names none we allow.
     */
    public function column(): string
    {
        return self::SORTABLE[$this->filters['sort'] ?? 'name'] ?? self::DEFAULT;
    }

    /**
     * The sort direction, from a whitelist of exactly two values.
     *
     * Re-checked here rather than trusted from the request: the query object is
     * also driven directly by the service and by tests, and a direction reaching
     * ORDER BY unchecked is a raw string in SQL.
     */
    public function direction(): string
    {
        return strtolower((string) ($this->filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Whether anything renders the reporting line, which is the only reason
     * the directory would pay for the join behind it.
     */
    public function sortsByManager(): bool
    {
        return ($this->filters['sort'] ?? null) === 'manager';
    }
}
