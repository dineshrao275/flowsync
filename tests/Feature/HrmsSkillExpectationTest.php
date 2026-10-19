<?php

namespace Tests\Feature;

use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Talent\EmployeeSkill;
use App\Models\Hrms\Talent\Skill;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 — role expectations per designation and the gap read against a person's levels. */
class HrmsSkillExpectationTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    public function test_gaps_are_expected_minus_level_and_assessment_wins(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.talent']);
        $des = Designation::query()->create(['name' => 'Analyst Z', 'slug' => 'analyst-z', 'code' => 'ANZ', 'level' => 3]);
        $sql = Skill::query()->create(['name' => 'SQL', 'is_active' => true]);
        $viz = Skill::query()->create(['name' => 'Charts', 'is_active' => true]);

        $worker = $this->userWith(['hrms.view']);
        $emp = $this->makeEmployee('Ana Analyst', ['user_id' => $worker->id, 'designation_id' => $des->id]);
        EmployeeSkill::query()->create(['employee_id' => $emp->id, 'skill_id' => $sql->id, 'level' => 2, 'source' => 'self']);

        $manager = $this->userWith(['hrms.view', 'hrms.talent.manage', 'hrms.talent.view_all']);
        $this->actAs($manager);
        $this->putJson("/api/hrms/skill-expectations/{$des->id}", ['skills' => [['skill_id' => $sql->id, 'level' => 4], ['skill_id' => $viz->id, 'level' => 3]]])->assertOk();

        $this->actAs($worker);
        $gaps = collect($this->getJson("/api/hrms/employees/{$emp->id}/skill-gaps")->assertOk()->json('gaps'))->keyBy('skill');
        $this->assertSame(2, $gaps['SQL']['gap']);       // 4 expected, 2 held
        $this->assertSame(3, $gaps['Charts']['gap']);    // nothing declared yet

        $this->putJson("/api/hrms/skill-expectations/{$des->id}", ['skills' => []])->assertForbidden(); // only talent managers write
    }
}
