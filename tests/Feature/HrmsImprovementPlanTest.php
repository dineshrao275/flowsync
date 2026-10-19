<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 — improvement plans: confidential, one active per person, milestones, closed with an outcome. */
class HrmsImprovementPlanTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $hr;

    private User $worker;

    private User $stranger;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
        $this->hr = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $this->worker = $this->userWith(['hrms.view']);
        $this->stranger = $this->userWith(['hrms.view', 'hrms.performance.view_all']);
        $this->emp = $this->makeEmployee('Wanda Worker', ['user_id' => $this->worker->id]);
    }

    private function open(array $over = []): array
    {
        $this->actAs($this->hr);

        return $this->postJson("/api/hrms/employees/{$this->emp->id}/improvement-plans", $over + [
            'reason' => 'Missed targets', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(60)->toDateString(),
            'milestones' => [['title' => 'Close 5 tickets'], ['title' => 'Weekly check-in']],
        ])->assertCreated()->json('plan');
    }

    public function test_a_plan_runs_from_open_to_close(): void
    {
        $plan = $this->open();
        $this->assertCount(2, $plan['milestones']);

        $id = $plan['milestones'][0]['id'];
        $this->putJson("/api/hrms/improvement-plans/{$plan['id']}/milestones/$id", ['done' => true])->assertOk();
        $this->postJson("/api/hrms/improvement-plans/{$plan['id']}/close", ['status' => 'completed', 'outcome' => ''])->assertStatus(422);
        $this->postJson("/api/hrms/improvement-plans/{$plan['id']}/close", ['status' => 'completed', 'outcome' => 'Targets met'])->assertOk()->assertJsonPath('plan.status', 'completed');
        $this->putJson("/api/hrms/improvement-plans/{$plan['id']}/milestones/$id", ['done' => false])->assertStatus(422);
    }

    public function test_only_one_active_plan_and_the_end_must_follow_the_start(): void
    {
        $this->open();
        $this->postJson("/api/hrms/employees/{$this->emp->id}/improvement-plans", ['reason' => 'x', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString()])->assertStatus(422);
    }

    public function test_it_is_confidential(): void
    {
        $this->open();

        $this->actAs($this->worker);
        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonCount(1, 'plans')->assertJsonPath('plans.0.can_manage', false);

        $this->actAs($this->stranger);
        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonCount(0, 'plans');
        $this->postJson("/api/hrms/employees/{$this->emp->id}/improvement-plans", ['reason' => 'x', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString()])->assertForbidden();
    }
}
