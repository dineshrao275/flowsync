<?php

namespace App\Services\Hrms\Employee;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — the reporting line.
 *
 * Its own class because the interesting part is not the assignment, it is the
 * question of whether the assignment is *safe*. Every approval that resolves a
 * "department head" step and every "everyone under X" payroll scope follows
 * `manager_id` upward and downward, so a single loop in this data turns those
 * into unbounded traversals in the middle of a payroll run — which is the worst
 * possible moment to discover it.
 *
 * Extracted so the cycle rule is stated once and cannot drift between the two
 * places that assign a manager.
 */
class ReportingLine
{
    /**
     * Re-parent an employee, or fail.
     *
     * @throws ValidationException 422 on a self-assignment or a cycle
     */
    public function reassign(Employee $employee, ?Employee $manager): Employee
    {
        $this->assertSane($employee, $manager);

        $employee->manager_id = $manager?->id;
        $employee->save();

        return $employee;
    }

    /**
     * Refuse a reporting line that would point at itself.
     *
     * @throws ValidationException 422
     */
    public function assertSane(Employee $employee, ?Employee $manager): void
    {
        if ($manager === null) {
            return;
        }

        if ($manager->id === $employee->id) {
            throw ValidationException::withMessages([
                'manager_id' => 'An employee cannot report to themselves.',
            ]);
        }

        if ($this->manages($manager, $employee->id)) {
            throw ValidationException::withMessages([
                'manager_id' => sprintf(
                    '%s already reports to %s somewhere below them, so this would create a loop.',
                    $employee->displayName(),
                    $manager->displayName(),
                ),
            ]);
        }
    }

    /**
     * Everyone beneath an employee, breadth-first.
     *
     * Depth is bounded by the number of employees, and a `seen` set catches a
     * cycle that already exists in imported data, so this terminates even on
     * corrupt input rather than only on well-formed input.
     *
     * @return list<Employee>
     */
    public function reportsOf(Employee $manager): array
    {
        $reports = [];
        $seen = [$manager->id => true];
        $frontier = [$manager->id];

        while ($frontier !== []) {
            $children = Employee::whereIn('manager_id', $frontier)->orderBy('name')->get();

            // The frontier is the *next* level, not this one: carrying this
            // level forward is what turns a cycle in the data into a loop.
            $frontier = [];

            foreach ($children as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }

                $seen[$child->id] = true;
                $reports[] = $child;
                $frontier[] = $child->id;
            }
        }

        return $reports;
    }

    /**
     * Whether `$manager` sits anywhere beneath `$employeeId` in the tree.
     */
    private function manages(Employee $manager, int $employeeId): bool
    {
        $seen = [];

        while ($manager->manager_id !== null) {
            if ($manager->manager_id === $employeeId) {
                return true;
            }

            // A pre-existing cycle would otherwise loop here forever.
            if (isset($seen[$manager->manager_id])) {
                return false;
            }

            $seen[$manager->manager_id] = true;

            $manager = Employee::find($manager->manager_id);

            if ($manager === null) {
                return false;
            }
        }

        return false;
    }
}
