<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms\Talent;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Talent\EmployeeSkill;
use App\Models\Hrms\Talent\Skill;
use App\Services\Hrms\HrmsScope;
use App\Services\Hrms\Talent\SkillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Skills catalogue (readable by everyone with HRMS access, edited by talent managers), self-declared
 * levels, manager assessments, and the employees × skills matrix scoped like every other HRMS read.
 */
class SkillController extends Controller
{
    private const BASE = 'hrms.talent';

    public function __construct(private readonly SkillService $skills) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'skills' => Skill::query()->withCount('employeeSkills as holders')->orderBy('category')->orderBy('name')->get()
                ->map(fn (Skill $s) => $this->present($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireManage($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:skills,name'],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['message' => __('Skill added.'), 'skill' => $this->present($this->skills->create($data, $request->user()))], 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $this->requireManage($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', 'unique:skills,name,'.$skill->id],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['message' => __('Skill updated.'), 'skill' => $this->present($this->skills->update($skill, $data, $request->user()))]);
    }

    public function destroy(Request $request, Skill $skill): JsonResponse
    {
        $this->requireManage($request);
        $this->skills->delete($skill, $request->user());

        return response()->json(['message' => __('Skill deleted.')]);
    }

    /** The caller's own declared and assessed levels. */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);

        return response()->json(['employee_id' => $employee->id, 'levels' => EmployeeSkill::query()->where('employee_id', $employee->id)->get()
            ->map(fn ($r) => ['skill_id' => $r->skill_id, 'level' => $r->level, 'source' => $r->source, 'note' => $r->note])->values()]);
    }

    public function saveMine(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $data = $this->validatedLevels($request);

        $this->skills->setLevels($employee, $data['skills'], EmployeeSkill::SELF, $request->user());

        return response()->json(['message' => __('Your skills were saved.')]);
    }

    /** A talent manager assesses someone's levels. */
    public function assess(Request $request, Employee $employee): JsonResponse
    {
        $this->requireManage($request);
        $data = $this->validatedLevels($request);

        $this->skills->setLevels($employee, $data['skills'], EmployeeSkill::ASSESSED, $request->user());

        return response()->json(['message' => __('Skills assessed.')]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(HrmsScope::canRead($user, self::BASE), 403, __('You cannot view the skills matrix.'));

        $filters = $request->validate([
            'skill_ids' => ['sometimes', 'array', 'max:40'],
            'skill_ids.*' => ['integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        // A see-all caller reads everyone; anyone else only their own scope (themself, plus reports for _assigned).
        $ids = HrmsScope::seesAll($user, self::BASE) ? null : HrmsScope::employeeIdsFor($user, self::BASE);

        return response()->json($this->skills->matrix($filters['skill_ids'] ?? null, $filters['department_id'] ?? null, $filters['q'] ?? null, $ids));
    }

    private function requireManage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('hrms.talent.manage'), 403, __('You cannot manage skills.'));
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 404, __('You have no employment record.'));

        return $employee;
    }

    /** @return array{skills: list<array{skill_id: int, level: int|null, note?: string|null}>} */
    private function validatedLevels(Request $request): array
    {
        return $request->validate([
            'skills' => ['required', 'array', 'min:1', 'max:100'],
            'skills.*.skill_id' => ['required', 'integer'],
            'skills.*.level' => ['present', 'nullable', 'integer', 'between:1,5'],
            'skills.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Skill $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'description' => $s->description, 'is_active' => $s->is_active, 'holders' => (int) ($s->holders ?? $s->employeeSkills()->count())];
    }
}
