<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\ReportsTo;
use Database\Factories\Hrms\EmployeeFactory;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase C step 12: the one definition of "_assigned".
 *
 * Every policy and list query that reads a manager's team goes through
 * ReportsTo::idsFor(User), so "your rows plus your direct reports' rows" is
 * stated once and cannot disagree between the HRMS contexts.
 */
class ReportsToTest extends TestCase
{
    use IsolatesDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeEmployee(User $user, ?int $managerId = null): Employee
    {
        return EmployeeFactory::new()->state([
            'user_id' => $user->id,
            'manager_id' => $managerId,
        ])->create();
    }

    public function test_ids_for_returns_the_direct_reports_user_ids(): void
    {
        $manager = $this->makeUser();
        $reportA = $this->makeUser();
        $reportB = $this->makeUser();
        $subordinateOfA = $this->makeUser();

        $this->makeEmployee($manager);
        $this->makeEmployee($reportA, Employee::where('user_id', $manager->id)->first()->id);
        $this->makeEmployee($reportB, Employee::where('user_id', $manager->id)->first()->id);
        $this->makeEmployee($subordinateOfA, Employee::where('user_id', $reportA->id)->first()->id);

        $this->assertSame(
            [$reportA->id, $reportB->id],
            ReportsTo::idsFor($manager),
        );

        // One hop only: the subordinate of a report is not the manager's report.
        $this->assertNotContains($subordinateOfA->id, ReportsTo::idsFor($manager));
    }

    public function test_ids_for_is_empty_for_a_login_without_an_employee_record(): void
    {
        $this->assertSame([], ReportsTo::idsFor($this->makeUser()));
    }

    public function test_ids_for_is_empty_for_a_manager_with_no_reports(): void
    {
        $manager = $this->makeUser();
        $loner = $this->makeUser();

        $this->makeEmployee($manager);
        $this->makeEmployee($loner, Employee::where('user_id', $manager->id)->first()->id);

        $this->assertSame([], ReportsTo::idsFor($loner));
    }

    public function test_the_memo_is_per_unit_of_work(): void
    {
        $manager = $this->makeUser();
        $this->makeEmployee($manager);

        $this->assertSame([], ReportsTo::idsFor($manager));

        // A manager edit must not be held past the unit of work — the same
        // boundary flush IsolatesDatabase applies between tests.
        $report = $this->makeEmployee(
            $this->makeUser(),
            Employee::where('user_id', $manager->id)->first()->id,
        );

        ReportsTo::resetMemo();

        $this->assertSame([$report->user_id], ReportsTo::idsFor($manager));
    }
}
