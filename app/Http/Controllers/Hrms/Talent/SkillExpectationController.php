<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms\Talent;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Talent\EmployeeSkill;
use App\Models\Hrms\Talent\Skill;
use App\Services\Hrms\HrmsScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Competency expectations and gaps. A designation lists the skill levels it is expected to hold; a gap is
 * expected minus the person's level (a talent manager's assessment wins over their own declaration). Gaps are a
 * conversation starter for a development plan — nothing is scored or stored per person.
 */
class SkillExpectationController extends Controller
{
    private const BASE = 'hrms.talent';

    public function index(): JsonResponse
    {
        $rows = DB::table('skill_expectations')->get(['designation_id', 'skill_id', 'expected_level']);

        return response()->json(['expectations' => $rows->groupBy('designation_id')->map(fn ($g) => $g->map(fn ($r) => ['skill_id' => (int) $r->skill_id, 'level' => (int) $r->expected_level])->values())]);
    }

    public function save(Request $request, int $designation): JsonResponse
    {
        abort_unless($request->user()->hasPermission('hrms.talent.manage'), 403, __('You cannot manage skills.'));
        abort_unless(DB::table('designations')->where('id', $designation)->exists(), 404);

        $data = $request->validate([
            'skills' => ['present', 'array', 'max:60'],
            'skills.*.skill_id' => ['required', 'integer', 'exists:skills,id'],
            'skills.*.level' => ['required', 'integer', 'between:1,5'],
        ]);

        DB::transaction(function () use ($designation, $data): void {
            DB::table('skill_expectations')->where('designation_id', $designation)->delete();
            foreach (collect($data['skills'])->unique('skill_id') as $row) {
                DB::table('skill_expectations')->insert([
                    'designation_id' => $designation, 'skill_id' => $row['skill_id'], 'expected_level' => $row['level'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => __('Expectations saved.')]);
    }

    public function gaps(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless(HrmsScope::coversEmployee($user, self::BASE, $employee), 403);

        $expected = DB::table('skill_expectations')->where('designation_id', $employee->designation_id)->get();
        $levels = EmployeeSkill::query()->where('employee_id', $employee->id)->get()->keyBy('skill_id');
        $skills = Skill::query()->whereIn('id', $expected->pluck('skill_id'))->pluck('name', 'id');

        $rows = $expected->map(function ($e) use ($levels, $skills) {
            $have = $levels->get($e->skill_id);

            return [
                'skill_id' => (int) $e->skill_id,
                'skill' => $skills[$e->skill_id] ?? '#'.$e->skill_id,
                'expected' => (int) $e->expected_level,
                'level' => $have?->level,
                'source' => $have?->source,
                'gap' => max(0, (int) $e->expected_level - (int) ($have?->level ?? 0)),
            ];
        })->sortByDesc('gap')->values();

        return response()->json(['employee' => ['id' => $employee->id, 'name' => $employee->name], 'has_designation' => $employee->designation_id !== null, 'gaps' => $rows]);
    }
}
