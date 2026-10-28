<?php

declare(strict_types=1);

namespace App\Services\Hrms\Talent;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Talent\EmployeeSkill;
use App\Models\Hrms\Talent\Skill;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HRMS Talent — the skills catalogue and the levels people hold.
 *
 * A level is 1-5, set by a person: the employee for themself (`self`) or a talent manager for
 * anyone (`assessed`, which also replaces a self-declared level). A skill in use is archived, never
 * deleted from under the people who hold it. The matrix is a grid of those entered levels plus a
 * per-skill head-count — no composite "skill score" per person exists anywhere.
 */
class SkillService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor): Skill
    {
        $skill = Skill::create(['name' => trim($data['name']), 'category' => $data['category'] ?? null, 'description' => $data['description'] ?? null, 'is_active' => true]);
        $this->audit->log($skill, 'skill.created', null, ['name' => $skill->name], $actor);

        return $skill;
    }

    /** @param array<string, mixed> $data */
    public function update(Skill $skill, array $data, ?User $actor): Skill
    {
        $before = $skill->only(['name', 'category', 'is_active']);
        $skill->fill(array_intersect_key($data, array_flip(['name', 'category', 'description', 'is_active'])))->save();
        $this->audit->log($skill, 'skill.updated', $before, $skill->only(['name', 'category', 'is_active']), $actor);

        return $skill->refresh();
    }

    public function delete(Skill $skill, ?User $actor): void
    {
        if ($skill->employeeSkills()->exists()) {
            throw ValidationException::withMessages(['form' => __('People hold this skill. Archive it instead of deleting it.')]);
        }

        $this->audit->log($skill, 'skill.deleted', ['name' => $skill->name], null, $actor);
        $skill->delete();
    }

    /**
     * Set (or clear, with a null level) one or more levels for an employee.
     *
     * @param  list<array{skill_id: int, level: int|null, note?: string|null}>  $levels
     */
    public function setLevels(Employee $employee, array $levels, string $source, ?User $actor): void
    {
        DB::transaction(function () use ($employee, $levels, $source, $actor): void {
            foreach ($levels as $row) {
                $skill = Skill::query()->where('is_active', true)->find($row['skill_id']);

                if ($skill === null) {
                    throw ValidationException::withMessages(['skills' => __('Choose skills from the active catalogue.')]);
                }

                $existing = EmployeeSkill::query()->where('employee_id', $employee->id)->where('skill_id', $skill->id)->first();

                // A self-declaration never overwrites or clears a manager's assessment: it stands until a manager changes it.
                if ($source === EmployeeSkill::SELF && $existing?->source === EmployeeSkill::ASSESSED) {
                    throw ValidationException::withMessages(['skills' => __('":skill" was assessed by a manager; ask them to update it.', ['skill' => $skill->name])]);
                }

                if ($row['level'] === null) {
                    if ($existing) {
                        $this->audit->log($existing, 'skill.level_cleared', ['level' => $existing->level], null, $actor);
                        $existing->delete();
                    }

                    continue;
                }

                $record = $existing ?? new EmployeeSkill(['employee_id' => $employee->id, 'skill_id' => $skill->id]);
                $before = $existing?->only(['level', 'source']);
                $record->fill([
                    'level' => (int) $row['level'],
                    'source' => $source,
                    'assessed_by_user_id' => $source === EmployeeSkill::ASSESSED ? $actor?->id : null,
                    'assessed_at' => now(),
                    'note' => $row['note'] ?? null,
                ])->save();

                $this->audit->log($record, 'skill.level_set', $before, $record->only(['level', 'source']), $actor);
            }
        });
    }

    /**
     * Employees × skills grid for the people the caller may see.
     *
     * @param  list<int>|null  $skillIds  restrict the columns
     * @param  list<int>|null  $employeeIds  null = every active employee
     * @return array{skills: list<array<string, mixed>>, employees: list<array<string, mixed>>, coverage: array<int, array<string, mixed>>}
     */
    public function matrix(?array $skillIds, ?int $departmentId, ?string $search, ?array $employeeIds = null): array
    {
        $skills = Skill::query()->where('is_active', true)->when($skillIds, fn ($q) => $q->whereIn('id', $skillIds))->orderBy('category')->orderBy('name')->get();

        $employees = Employee::query()->active()
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->orderBy('name')->limit(200)->get(['id', 'employee_code', 'name']);

        $levels = EmployeeSkill::query()->whereIn('employee_id', $employees->pluck('id'))->whereIn('skill_id', $skills->pluck('id'))->get()
            ->groupBy('employee_id');

        $coverage = [];
        foreach ($skills as $skill) {
            $coverage[$skill->id] = ['holders' => 0, 'expert' => 0];
        }

        $rows = $employees->map(function ($e) use ($levels, &$coverage): array {
            $mine = ($levels[$e->id] ?? collect())->keyBy('skill_id');
            foreach ($mine as $skillId => $row) {
                $coverage[$skillId]['holders']++;
                $coverage[$skillId]['expert'] += $row->level >= 4 ? 1 : 0;
            }

            return [
                'id' => $e->id, 'employee_code' => $e->employee_code, 'name' => $e->name,
                'levels' => $mine->map(fn ($r) => ['level' => $r->level, 'source' => $r->source])->all(),
            ];
        })->all();

        return [
            'skills' => $skills->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category])->values()->all(),
            'employees' => $rows,
            'coverage' => $coverage,
        ];
    }
}
