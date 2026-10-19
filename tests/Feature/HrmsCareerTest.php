<?php

namespace Tests\Feature;

use App\Models\Hrms\Org\Designation;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 — career paths are visible to everyone; succession is confidential to talent managers. */
class HrmsCareerTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    public function test_paths_are_public_to_read_and_manager_only_to_write(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.talent']);
        $a = Designation::query()->create(['name' => 'Junior Q', 'slug' => 'junior-q', 'code' => 'JQ', 'level' => 1]);
        $b = Designation::query()->create(['name' => 'Senior Q', 'slug' => 'senior-q', 'code' => 'SQ', 'level' => 2]);

        $this->actAs($this->userWith(['hrms.view']));
        $this->postJson('/api/hrms/career-paths', ['from_designation_id' => $a->id, 'to_designation_id' => $b->id])->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.manage']));
        $this->postJson('/api/hrms/career-paths', ['from_designation_id' => $a->id, 'to_designation_id' => $b->id])->assertCreated();
        $this->postJson('/api/hrms/career-paths', ['from_designation_id' => $a->id, 'to_designation_id' => $b->id])->assertStatus(422);

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/career-paths')->assertOk()->assertJsonPath('paths.0.from', 'Junior Q')->assertJsonPath('paths.0.to', 'Senior Q');
    }

    public function test_succession_is_confidential_and_a_holder_cannot_succeed_themself(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.talent']);
        $holder = $this->makeEmployee('Hal Holder');
        $heir = $this->makeEmployee('Hera Heir');

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.view_all']));
        $this->getJson('/api/hrms/succession')->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.manage']));
        $this->postJson('/api/hrms/succession', ['title' => 'Head of Ops', 'incumbent_employee_id' => $holder->id, 'risk' => 'high'])->assertCreated();
        $position = $this->getJson('/api/hrms/succession')->assertOk()->json('positions.0.id');

        $this->putJson("/api/hrms/succession/$position/candidates", ['employee_id' => $holder->id, 'readiness' => 'now'])->assertStatus(422);
        $this->putJson("/api/hrms/succession/$position/candidates", ['employee_id' => $heir->id, 'readiness' => 'one_two_years'])->assertOk();
        $this->getJson('/api/hrms/succession')->assertJsonPath('positions.0.candidates.0.name', 'Hera Heir');
    }
}
