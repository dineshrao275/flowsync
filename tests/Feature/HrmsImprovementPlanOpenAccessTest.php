<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The improvement-plans page offers "Open a plan" only to people who can actually open one: a
 * performance manager, or the direct manager of someone. The list answers it (`can_open`) so the
 * page does not show a dead-end form (or ask for the employee directory) to everybody else.
 */
class HrmsImprovementPlanOpenAccessTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
    }

    public function test_a_performance_manager_can_open_plans(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage']));

        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonPath('can_open', true);
    }

    public function test_a_direct_manager_can_open_plans_without_the_manage_permission(): void
    {
        $manager = $this->userWith(['hrms.view']);
        $own = $this->makeEmployee('Mia Manager', ['user_id' => $manager->id]);
        $this->makeEmployee('Dan Direct', ['manager_id' => $own->id]);

        $this->actAs($manager);

        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonPath('can_open', true);
    }

    public function test_an_ordinary_employee_and_a_broad_viewer_cannot(): void
    {
        $worker = $this->userWith(['hrms.view']);
        $this->makeEmployee('Wanda Worker', ['user_id' => $worker->id]);
        $viewer = $this->userWith(['hrms.view', 'hrms.performance.view_all']);

        $this->actAs($worker);
        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonPath('can_open', false);

        $this->actAs($viewer);
        $this->getJson('/api/hrms/improvement-plans')->assertOk()->assertJsonPath('can_open', false);
    }
}
