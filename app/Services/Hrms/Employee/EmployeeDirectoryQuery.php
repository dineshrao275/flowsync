<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmploymentType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Employee/HRMS — the employee directory query.
 *
 * Extracted from `EmployeeService` because filtering is the one part of the
 * service that is neither a rule nor a write: it is a whitelist of filters, a
 * whitelist of sortable columns, and nothing else. Keeping it here stops the
 * service growing a `match` on every new column, and gives the list and the
 * count one definition instead of two that can disagree.
 *
 * Soft-deleted employees are excluded by the model's global scope — a trashed
 * record must not appear in a roster — so no filter here needs to know about it.
 */
class EmployeeDirectoryQuery
{
    /**
     * Apply a filter set to the base employee query.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function build(array $filters = []): Builder
    {
        $query = Employee::query();

        $this->applySearch($query, $filters);
        $this->applyStatus($query, $filters);
        $this->applyWorkMode($query, $filters);
        $this->applyRelations($query, $filters);
        $this->applyJoinedRange($query, $filters);

        return $query;
    }

    /**
     * The sorted, paginated result.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $sort = new EmployeeDirectorySort($filters);

        return $this->build($filters)
            ->with($this->relations($filters, $sort))
            ->orderBy($sort->column(), $sort->direction())
            // The id tiebreak goes the same way: a second sort key disagreeing
            // with the first makes page 2 of a desc-sorted list reshuffle rows
            // the caller has already seen.
            ->orderBy('employees.id', $sort->direction())
            ->paginate($this->perPage($filters));
    }

    /**
     * How many rows the same filters match — the pagination footer's total.
     *
     * Reuses {@see self::build()} so the footer can never disagree with the
     * page, which is the failure mode when the two are written separately.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->build($filters)->toBase()->getCountForPagination();
    }

    /**
     * Everyone who can be picked as a manager.
     *
     * *Everyone*, not just the people who already have a report — that list
     * cannot seed a reporting line: a new hire reporting to someone who has
     * never managed anyone is a perfectly ordinary first assignment, and with a
     * reports-only list the select is empty and the choice is impossible to
     * make. The same list serves the directory's manager filter, where picking
     * a manager with no reports honestly returns nothing.
     *
     * Separate from {@see filterOptions()} because the profile screen needs only
     * this list, and building the whole directory scope to get it would run a
     * count and a per-status group-by on every profile open.
     *
     * @return Collection<int, object>
     */
    public function managerOptions(): Collection
    {
        return Employee::query()
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code']);
    }

    /**
     * The option lists that go with a directory request.
     *
     * @param  array<string, mixed>  $filters
     * @return array{employment_types: Collection<int, object>, managers: Collection<int, object>, statuses: list<array{value: string, label: string}>}
     */
    public function filterOptions(array $filters = []): array
    {
        return [
            // The whole active catalog, not the types currently in use: a type
            // nobody holds yet is exactly the one a reader wants to filter by
            // to confirm it is empty.
            'employment_types' => EmploymentType::query()
                ->where('is_active', true)
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            // The picker list, not a reports-only list — see managerOptions().
            'managers' => $this->managerOptions(),
            'statuses' => array_map(
                fn (EmployeeStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                EmployeeStatus::cases(),
            ),
            // Served from the enum rather than hardcoded in the SPA. A JS copy of
            // a PHP enum cannot be caught by a failing test — it just quietly
            // offers a work mode the API rejects — so the catalog travels with the
            // response and the form renders whatever the server supports.
            'work_modes' => array_map(
                fn (WorkMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                WorkMode::cases(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function relations(array $filters, ?EmployeeDirectorySort $sort = null): array
    {
        $relations = ['user', 'employmentType'];

        // Only eager-load the reporting line when something actually renders it,
        // otherwise every row in an unfiltered directory pays for two joins.
        if (! empty($filters['manager_id']) || ($sort ?? new EmployeeDirectorySort($filters))->sortsByManager()) {
            $relations[] = 'manager';
        }

        return $relations;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, array $filters): void
    {
        $term = trim((string) ($filters['q'] ?? ''));

        if ($term === '') {
            return;
        }

        // Escaped because this is a LIKE pattern, not a plain string: an
        // unescaped `%` in a search box would otherwise match every row, and
        // `_` would match any character.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $inner) use ($escaped) {
            $inner->where('employees.name', 'like', "%{$escaped}%")
                ->orWhere('employees.employee_code', 'like', "%{$escaped}%")
                ->orWhere('employees.personal_email', 'like', "%{$escaped}%");
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applyStatus(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if ($status === null || $status === '') {
            return;
        }

        // Accepts a single value or a list, so the UI can offer a multi-select
        // without a second code path.
        foreach ((array) $status as $value) {
            EmployeeStatus::from((string) $value);
        }

        $query->whereIn('employees.status', (array) $status);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applyWorkMode(Builder $query, array $filters): void
    {
        if (empty($filters['work_mode'])) {
            return;
        }

        foreach ((array) $filters['work_mode'] as $value) {
            WorkMode::from((string) $value);
        }

        $query->whereIn('employees.work_mode', (array) $filters['work_mode']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applyRelations(Builder $query, array $filters): void
    {
        if (! empty($filters['employment_type_id'])) {
            $query->where('employees.employment_type_id', $filters['employment_type_id']);
        }

        if (! empty($filters['manager_id'])) {
            $query->where('employees.manager_id', $filters['manager_id']);
        }

        $this->applyOrgFilters($query, $filters);
    }

    /**
     * The three org references (P3.3), each an exact match.
     *
     * Exact, never a subtree: "everyone in Engineering" and "everyone under
     * Engineering" are different questions, and a directory that quietly
     * answered the second when asked the first would understate a team's size.
     * The subtree walk belongs to the org context, which is the only place that
     * has the tree loaded and can afford to walk it.
     *
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applyOrgFilters(Builder $query, array $filters): void
    {
        foreach (['department_id', 'designation_id', 'location_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("employees.{$column}", $filters[$column]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Employee>  $query
     */
    private function applyJoinedRange(Builder $query, array $filters): void
    {
        if (! empty($filters['joined_from'])) {
            $query->whereDate('employees.joining_date', '>=', $filters['joined_from']);
        }

        if (! empty($filters['joined_to'])) {
            $query->whereDate('employees.joining_date', '<=', $filters['joined_to']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        return (int) min(max((int) ($filters['per_page'] ?? 25), 1), 100);
    }
}
