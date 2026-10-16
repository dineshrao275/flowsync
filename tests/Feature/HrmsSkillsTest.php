<?php

namespace Tests\Feature;

use App\Models\Hrms\Talent\EmployeeSkill;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F8 (M-C1) — skills catalogue, self-declared and assessed levels, and the scoped matrix. */
class HrmsSkillsTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.talent']);
    }

    private function manager(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.view', 'hrms.talent.manage']));
    }

    private function skill(string $name = 'Laravel', ?string $category = 'Engineering'): int
    {
        return $this->postJson('/api/hrms/skills', ['name' => $name, 'category' => $category])->assertCreated()->json('skill.id');
    }

    public function test_only_talent_managers_edit_the_catalogue_but_everyone_can_read_it(): void
    {
        $this->manager();
        $id = $this->skill();

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/skills')->assertOk()->assertJsonCount(1, 'skills');
        $this->postJson('/api/hrms/skills', ['name' => 'Nope'])->assertForbidden();
        $this->putJson("/api/hrms/skills/{$id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/hrms/skills/{$id}")->assertForbidden();
    }

    public function test_names_are_unique_and_a_skill_in_use_cannot_be_deleted(): void
    {
        $this->manager();
        $id = $this->skill();
        $this->postJson('/api/hrms/skills', ['name' => 'Laravel'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $holder = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Holder', ['user_id' => $holder->id]);
        $this->actAs($holder);
        $this->putJson('/api/hrms/skills/mine', ['skills' => [['skill_id' => $id, 'level' => 3]]])->assertOk();

        $this->manager();
        $this->deleteJson("/api/hrms/skills/{$id}")->assertUnprocessable();
        $this->putJson("/api/hrms/skills/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('skill.is_active', false);
        $this->assertSame(1, EmployeeSkill::where('employee_id', $employee->id)->count());
    }

    public function test_an_employee_declares_levels_and_a_manager_assessment_wins(): void
    {
        $this->manager();
        $php = $this->skill('PHP');

        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Dev', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->putJson('/api/hrms/skills/mine', ['skills' => [['skill_id' => $php, 'level' => 2]]])->assertOk();
        $this->getJson('/api/hrms/skills/mine')->assertJsonPath('levels.0.level', 2)->assertJsonPath('levels.0.source', 'self');
        $this->putJson('/api/hrms/skills/mine', ['skills' => [['skill_id' => $php, 'level' => 6]]])->assertUnprocessable();

        $this->manager();
        $this->putJson("/api/hrms/employees/{$employee->id}/skills", ['skills' => [['skill_id' => $php, 'level' => 4]]])->assertOk();

        // The manager's level now stands; the employee cannot overwrite it.
        $this->actAs($user);
        $this->putJson('/api/hrms/skills/mine', ['skills' => [['skill_id' => $php, 'level' => 1]]])->assertUnprocessable();
        $row = EmployeeSkill::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame([4, 'assessed'], [$row->level, $row->source]);

        // A person can clear their own self-declared level but not an assessed one.
        $this->putJson('/api/hrms/skills/mine', ['skills' => [['skill_id' => $php, 'level' => null]]])->assertUnprocessable();
    }

    public function test_the_matrix_lists_levels_with_coverage_and_follows_the_view_scope(): void
    {
        $this->manager();
        $php = $this->skill('PHP');
        $sql = $this->skill('SQL');

        $a = $this->makeEmployee('Alice');
        $b = $this->makeEmployee('Bob');
        $this->putJson("/api/hrms/employees/{$a->id}/skills", ['skills' => [['skill_id' => $php, 'level' => 5], ['skill_id' => $sql, 'level' => 2]]])->assertOk();
        $this->putJson("/api/hrms/employees/{$b->id}/skills", ['skills' => [['skill_id' => $php, 'level' => 3]]])->assertOk();

        $matrix = $this->getJson('/api/hrms/skills/matrix')->assertOk()->json();
        $this->assertCount(2, $matrix['skills']);
        $alice = collect($matrix['employees'])->firstWhere('name', 'Alice');
        $this->assertSame(5, $alice['levels'][$php]['level']);
        $this->assertSame(['holders' => 2, 'expert' => 1], $matrix['coverage'][$php]);

        // Without any talent grant there is no matrix; with only the own-scope grant a person sees only themself.
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/skills/matrix')->assertForbidden();

        $user = $this->userWith(['hrms.view', 'hrms.talent.view_own']);
        $this->makeEmployee('Carol', ['user_id' => $user->id]);
        $this->actAs($user);
        $names = collect($this->getJson('/api/hrms/skills/matrix')->assertOk()->json('employees'))->pluck('name')->all();
        $this->assertSame(['Carol'], $names);
    }

    public function test_the_talent_module_gates_the_skills_routes(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $this->manager();

        $this->getJson('/api/hrms/skills')->assertForbidden();
    }
}
