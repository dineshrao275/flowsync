<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms\Talent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Career paths and succession plans.
 *
 * Paths are public knowledge inside the company: anyone with HR access sees which designation leads where.
 * Succession is the opposite — who is considered a successor for whom is confidential — so every succession
 * route needs `hrms.talent.manage`. Readiness is a human judgement recorded as one of three words; nothing is scored.
 */
class CareerController extends Controller
{
    private const READINESS = ['now', 'one_two_years', 'three_plus'];

    private const RISK = ['low', 'medium', 'high'];

    public function paths(): JsonResponse
    {
        $names = DB::table('designations')->pluck('name', 'id');

        return response()->json(['paths' => DB::table('career_paths')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'from_id' => (int) $p->from_designation_id, 'from' => $names[$p->from_designation_id] ?? null,
            'to_id' => (int) $p->to_designation_id, 'to' => $names[$p->to_designation_id] ?? null, 'note' => $p->note,
        ])->values()]);
    }

    public function addPath(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'from_designation_id' => ['required', 'integer', 'exists:designations,id', 'different:to_designation_id'],
            'to_designation_id' => ['required', 'integer', 'exists:designations,id'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        if (DB::table('career_paths')->where('from_designation_id', $data['from_designation_id'])->where('to_designation_id', $data['to_designation_id'])->exists()) {
            throw ValidationException::withMessages(['form' => __('That path already exists.')]);
        }

        DB::table('career_paths')->insert($data + ['created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => __('Path added.')], 201);
    }

    public function removePath(Request $request, int $path): JsonResponse
    {
        $this->manage($request);
        DB::table('career_paths')->where('id', $path)->delete();

        return response()->json(['message' => __('Path removed.')]);
    }

    public function succession(Request $request): JsonResponse
    {
        $this->manage($request);
        $people = DB::table('employees')->pluck('name', 'id');
        $candidates = DB::table('succession_candidates')->orderBy('id')->get()->groupBy('position_id');

        return response()->json([
            'positions' => DB::table('succession_positions')->orderByDesc('id')->get()->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'risk' => $p->risk, 'note' => $p->note,
                'incumbent' => $p->incumbent_employee_id ? ['id' => (int) $p->incumbent_employee_id, 'name' => $people[$p->incumbent_employee_id] ?? null] : null,
                'candidates' => ($candidates[$p->id] ?? collect())->map(fn ($c) => [
                    'id' => $c->id, 'employee_id' => (int) $c->employee_id, 'name' => $people[$c->employee_id] ?? null, 'readiness' => $c->readiness, 'note' => $c->note,
                ])->values(),
            ])->values(),
        ]);
    }

    public function addPosition(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'incumbent_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'risk' => ['required', Rule::in(self::RISK)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::table('succession_positions')->insert($data + ['incumbent_employee_id' => $data['incumbent_employee_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => __('Position added.')], 201);
    }

    public function removePosition(Request $request, int $position): JsonResponse
    {
        $this->manage($request);
        DB::table('succession_positions')->where('id', $position)->delete();

        return response()->json(['message' => __('Position removed.')]);
    }

    public function setCandidate(Request $request, int $position): JsonResponse
    {
        $this->manage($request);
        abort_unless(DB::table('succession_positions')->where('id', $position)->exists(), 404);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'readiness' => ['required', Rule::in(self::READINESS)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $incumbent = DB::table('succession_positions')->where('id', $position)->value('incumbent_employee_id');
        if ((int) $incumbent === (int) $data['employee_id']) {
            throw ValidationException::withMessages(['employee_id' => __('The current holder cannot be their own successor.')]);
        }

        DB::table('succession_candidates')->updateOrInsert(
            ['position_id' => $position, 'employee_id' => $data['employee_id']],
            ['readiness' => $data['readiness'], 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['message' => __('Successor saved.')]);
    }

    public function removeCandidate(Request $request, int $candidate): JsonResponse
    {
        $this->manage($request);
        DB::table('succession_candidates')->where('id', $candidate)->delete();

        return response()->json(['message' => __('Successor removed.')]);
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()->hasPermission('hrms.talent.manage'), 403, __('You cannot manage skills.'));
    }
}
