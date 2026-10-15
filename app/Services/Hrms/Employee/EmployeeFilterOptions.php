<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
use Illuminate\Support\Collection;

/**
 * Employee/HRMS — the catalogues that go with a directory request.
 *
 * Split out of `EmployeeDirectoryQuery` when P3.3's org filters pushed that
 * class back over the 300-line ceiling, and the split is honest rather than
 * convenient: this returns *data about the catalogue*, whereas
 * `EmployeeDirectoryQuery` returns a *query* over one table. They had grown into
 * different things and were only sharing a file.
 *
 * Two rules everything here follows:
 *
 *  - **A list is a catalogue, not the current result set.** The employment types
 *    are the whole active catalogue rather than the types in use, because a type
 *    nobody holds yet is exactly what a reader filters by to confirm it is empty.
 *  - **A JS copy of a PHP enum cannot be caught by a failing test.** `statuses`
 *    and `work_modes` are mapped from the enums here and rendered from the
 *    response, so a form cannot quietly offer a value the API rejects.
 */
class EmployeeFilterOptions
{
    /**
     * Every option list the directory and the profile form render from.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function all(array $filters = []): array
    {
        return [
            'employment_types' => $this->employmentTypes(),
            // The picker list, not a reports-only list — see people().
            'managers' => $this->people(),
            ...$this->orgCatalogues(),
            'statuses' => array_map(
                fn (EmployeeStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                EmployeeStatus::cases(),
            ),
            'work_modes' => array_map(
                fn (WorkMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                WorkMode::cases(),
            ),
        ];
    }

    /**
     * The whole active employment-type catalogue.
     *
     * @return Collection<int, object>
     */
    public function employmentTypes(): Collection
    {
        return EmploymentType::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * *Everyone*, not just the people who already have a report — that list
     * cannot seed a reporting line: a new hire reporting to someone who has
     * never managed anyone is a perfectly ordinary first assignment, and with a
     * reports-only list the select is empty and the choice is impossible to make.
     * The same list serves the directory's manager filter, where picking a
     * manager with no reports honestly returns nothing.
     *
     * @return Collection<int, object>
     */
    public function people(): Collection
    {
        return Employee::query()
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code']);
    }

    /**
     * The three org catalogues (P3.3), as filter options.
     *
     * They have to come from the employee endpoint rather than from
     * `GET api/hrms/org`: a reader holding `hrms.employees.view` without
     * `hrms.org.view` is allowed to filter the directory by department but is not
     * allowed to read the org chart, so borrowing the org endpoint would leave
     * them with a select the API refuses to fill — worse than no filter at all.
     *
     * Inactive rows are included on purpose. A department somebody retired is
     * exactly the one a reader filters by to find out who is still in it.
     *
     * @return array<string, Collection<int, object>>
     */
    private function orgCatalogues(): array
    {
        $columns = ['id', 'name'];

        return [
            'departments' => Department::query()->orderBy('name')->get($columns),
            'designations' => Designation::query()->orderBy('name')->get($columns),
            'locations' => Location::query()->orderBy('name')->get($columns),
        ];
    }
}
