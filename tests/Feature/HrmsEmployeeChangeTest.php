<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmployeeChange;
use App\Models\Hrms\Org\Department;
use App\Models\Role;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F6.3 — transfers / promotions / confirmations: request, approve, apply on the effective date. */
class HrmsEmployeeChangeTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $hr;

    private User $hr2;

    private Employee $person;

    private int $newDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core']);

        $this->hr = $this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.employees.manage']);
        $this->hr->roles()->syncWithoutDetaching([Role::where('slug', 'hr_manager')->value('id')]);
        $this->hr = $this->hr->fresh(['roles.permissions']);

        // A second HR user: the requester can never approve their own ask, so another one settles it.
        $this->hr2 = $this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.employees.manage']);
        $this->hr2->roles()->syncWithoutDetaching([Role::where('slug', 'hr_manager')->value('id')]);
        $this->hr2 = $this->hr2->fresh(['roles.permissions']);

        $this->person = $this->makeEmployee('Pat Person');
        $this->newDepartment = Department::query()->create(['name' => 'Platform', 'slug' => 'platform-x', 'code' => 'PLX'])->id;
    }

    private function request(array $over = []): array
    {
        $this->actAs($this->hr);

        $change = $this->postJson("/api/hrms/employees/{$this->person->id}/changes", $over + [
            'type' => 'transfer',
            'effective_date' => now()->toDateString(),
            'changes' => ['department_id' => $this->newDepartment],
            'reason' => 'Team restructure',
        ])->assertCreated()->json('change');

        $this->assertSame('pending', $change['status']);
        $this->actAs($this->hr2);

        return $this->postJson("/api/hrms/employee-changes/{$change['id']}/approve")->assertOk()->json('change');
    }

    public function test_an_hr_requester_who_also_holds_the_hr_role_is_applied_immediately_when_due(): void
    {
        $change = $this->request();

        // Filed by one HR user, approved by another: due today, so it is applied on approval.
        $this->assertSame('applied', $change['status']);
        $this->assertSame($this->newDepartment, $this->person->fresh()->department_id);
    }

    public function test_a_future_dated_change_waits_and_the_command_applies_it_on_its_date(): void
    {
        $change = $this->request(['effective_date' => now()->addDays(5)->toDateString()]);

        $this->assertSame('approved', $change['status']);
        $this->assertNull($this->person->fresh()->department_id);

        $this->artisan('hrms:apply-employee-changes', ['--tenant' => $this->acme()->id])->assertExitCode(0);
        $this->assertNull($this->person->fresh()->department_id, 'not due yet');

        $this->travel(6)->days();
        $this->artisan('hrms:apply-employee-changes', ['--tenant' => $this->acme()->id])->assertExitCode(0);

        $this->assertSame($this->newDepartment, $this->person->fresh()->department_id);
        $this->assertSame('applied', EmployeeChange::firstOrFail()->status);
    }

    public function test_a_confirmation_stamps_the_confirmation_date(): void
    {
        $date = now()->toDateString();
        $this->request(['type' => 'confirmation', 'effective_date' => $date, 'changes' => []]);

        $this->assertSame($date, $this->person->fresh()->confirmation_date->toDateString());
    }

    public function test_requests_need_manager_or_hr_and_valid_changes(): void
    {
        $this->actAs($this->userWith(['hrms.view']));
        $this->postJson("/api/hrms/employees/{$this->person->id}/changes", ['type' => 'transfer', 'effective_date' => now()->toDateString(), 'changes' => ['department_id' => $this->newDepartment]])->assertForbidden();

        $this->actAs($this->hr);
        $this->postJson("/api/hrms/employees/{$this->person->id}/changes", ['type' => 'transfer', 'effective_date' => now()->toDateString(), 'changes' => []])->assertUnprocessable();
        $this->postJson("/api/hrms/employees/{$this->person->id}/changes", ['type' => 'transfer', 'effective_date' => now()->toDateString(), 'changes' => ['department_id' => 99999]])->assertUnprocessable();
        $this->postJson("/api/hrms/employees/{$this->person->id}/changes", ['type' => 'promotion', 'effective_date' => now()->subDay()->toDateString(), 'changes' => ['designation' => 'Lead']])->assertUnprocessable();
    }
}
