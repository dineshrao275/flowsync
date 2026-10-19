<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 — development plans belong to the employee; strangers see nothing. */
class HrmsDevelopmentPlanTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $me;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
        $this->me = $this->userWith(['hrms.view']);
        $this->emp = $this->makeEmployee('Dev Dana', ['user_id' => $this->me->id]);
    }

    public function test_an_employee_runs_their_own_plan(): void
    {
        $this->actAs($this->me);
        $plan = $this->postJson('/api/hrms/development-plans', [
            'title' => 'Learn SQL', 'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString(),
            'actions' => [['title' => 'Finish course']],
        ])->assertCreated()->json('plan');

        $id = $plan['actions'][0]['id'];
        $this->putJson("/api/hrms/development-plans/{$plan['id']}/actions/$id", ['done' => true])->assertOk()->assertJsonPath('plan.actions.0.done', true);
        $this->postJson("/api/hrms/development-plans/{$plan['id']}/close", ['status' => 'completed'])->assertOk()->assertJsonPath('plan.status', 'completed');
    }

    public function test_a_stranger_sees_nothing_and_cannot_open_one_for_someone_else(): void
    {
        $this->actAs($this->me);
        $this->postJson('/api/hrms/development-plans', ['title' => 'Mine', 'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString()])->assertCreated();

        $stranger = $this->userWith(['hrms.view', 'hrms.performance.view_all']);
        $this->makeEmployee('Sam Stranger', ['user_id' => $stranger->id]);
        $this->actAs($stranger);
        $this->getJson('/api/hrms/development-plans')->assertOk()->assertJsonCount(0, 'plans');
        $this->postJson('/api/hrms/development-plans', ['employee_id' => $this->emp->id, 'title' => 'x', 'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString()])->assertForbidden();
    }
}
