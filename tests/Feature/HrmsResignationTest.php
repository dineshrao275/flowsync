<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\ResignationRequest;
use App\Models\Role;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F6.4 — resignation self-service through the approval engine, opening the exit case on approval. */
class HrmsResignationTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $employeeUser;

    private User $managerUser;

    private User $hrUser;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.offboarding']);

        $this->managerUser = $this->userWith(['hrms.view']);
        $manager = $this->makeEmployee('Boss', ['user_id' => $this->managerUser->id]);

        $this->employeeUser = $this->userWith(['hrms.view']);
        $this->employee = $this->makeEmployee('Leaver', ['user_id' => $this->employeeUser->id, 'manager_id' => $manager->id]);

        $this->hrUser = $this->userWith(['hrms.view']);
        $this->hrUser->roles()->syncWithoutDetaching([Role::where('slug', 'hr_manager')->value('id')]);
        $this->hrUser = $this->hrUser->fresh(['roles.permissions']);
    }

    private function file(array $over = []): array
    {
        $this->actAs($this->employeeUser);

        return $this->postJson('/api/hrms/resignations', $over + [
            'requested_last_day' => now()->addDays(30)->toDateString(),
            'reason' => 'Moving abroad.',
            'notice_period_days' => 30,
        ])->assertCreated()->json('resignation');
    }

    public function test_manager_then_hr_approve_and_the_exit_case_opens_itself(): void
    {
        $r = $this->file();
        $this->assertSame('pending', $r['status']);

        $this->actAs($this->managerUser);
        $this->assertCount(1, $this->getJson('/api/hrms/resignations')->json('to_decide'));
        $this->postJson("/api/hrms/resignations/{$r['id']}/approve")->assertOk()->assertJsonPath('resignation.status', 'pending');
        $this->assertSame(0, OffboardingCase::count());

        $this->actAs($this->hrUser);
        $this->postJson("/api/hrms/resignations/{$r['id']}/approve", ['note' => 'Wishing you well'])->assertOk()->assertJsonPath('resignation.status', 'approved');

        $case = OffboardingCase::firstOrFail();
        $this->assertSame($this->employee->id, $case->employee_id);
        $this->assertSame($r['requested_last_day'], $case->last_working_day->toDateString());
        $this->assertSame('resigned', $case->reason->value ?? $case->reason);
        $this->assertSame($r['requested_last_day'], $this->employee->fresh()->exit_date->toDateString());
        $this->assertSame($case->id, ResignationRequest::firstOrFail()->offboarding_case_id);
    }

    public function test_a_rejection_needs_a_reason_and_opens_no_exit(): void
    {
        $r = $this->file();
        $this->actAs($this->managerUser);

        $this->postJson("/api/hrms/resignations/{$r['id']}/reject", [])->assertUnprocessable();
        $this->postJson("/api/hrms/resignations/{$r['id']}/reject", ['note' => 'Please talk to me first'])->assertOk()->assertJsonPath('resignation.status', 'rejected');

        $this->assertSame(0, OffboardingCase::count());
        $this->actAs($this->employeeUser);
        $this->assertSame('Please talk to me first', $this->getJson('/api/hrms/resignations')->json('mine.0.decision_note'));
    }

    public function test_only_the_employee_withdraws_and_only_while_open(): void
    {
        $r = $this->file();

        $this->actAs($this->managerUser);
        $this->postJson("/api/hrms/resignations/{$r['id']}/withdraw")->assertForbidden();

        $this->actAs($this->employeeUser);
        $this->postJson("/api/hrms/resignations/{$r['id']}/withdraw")->assertOk()->assertJsonPath('resignation.status', 'withdrawn');
        $this->postJson("/api/hrms/resignations/{$r['id']}/withdraw")->assertUnprocessable();

        // After a withdrawal a new resignation is allowed.
        $this->postJson('/api/hrms/resignations', ['requested_last_day' => now()->addDays(10)->toDateString(), 'reason' => 'Changed my mind again.'])->assertCreated();
    }

    public function test_guards_on_filing(): void
    {
        $this->actAs($this->employeeUser);

        $this->postJson('/api/hrms/resignations', ['requested_last_day' => now()->subDay()->toDateString(), 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('requested_last_day');
        $this->postJson('/api/hrms/resignations', ['requested_last_day' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->file();
        $this->postJson('/api/hrms/resignations', ['requested_last_day' => now()->addDays(40)->toDateString(), 'reason' => 'Twice'])->assertUnprocessable();

        // A user with no employment record cannot resign.
        $this->actAs($this->userWith(['hrms.view']));
        $this->postJson('/api/hrms/resignations', ['requested_last_day' => now()->addDay()->toDateString(), 'reason' => 'Ghost'])->assertForbidden();
    }

    public function test_a_stranger_cannot_decide_and_sees_nothing_to_decide(): void
    {
        $r = $this->file();

        $this->actAs($this->userWith(['hrms.view']));
        $this->assertCount(0, $this->getJson('/api/hrms/resignations')->json('to_decide'));
        $this->postJson("/api/hrms/resignations/{$r['id']}/approve")->assertUnprocessable();
        $this->assertSame(0, OffboardingCase::count());
    }

    public function test_the_offboarding_module_gates_resignations(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $this->actAs($this->employeeUser);

        $this->getJson('/api/hrms/resignations')->assertForbidden();
    }
}
