<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CheckInRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\CheckIn;
use App\Models\Hrms\Performance\PerformanceCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Performance/HRMS — check-in notes over HTTP.
 *
 * Notes live under their cycle in the URL; the employee travels in the
 * body, and the policy answers self-or-manage for whoever it names.
 * Append-only: there is no update endpoint, because a dated note is
 * history the moment it is written.
 */
class CheckInController extends Controller
{
    public function index(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewAny', CheckIn::class);

        $query = $cycle->checkIns()->with('employee:id,employee_code,name')->orderByDesc('id');

        if ($request->has('employee_id')) {
            $query->where('employee_id', (int) $request->query('employee_id'));
        }

        return response()->json([
            'check_ins' => $query->get()->map(fn (CheckIn $checkIn): array => $this->present($checkIn))->all(),
        ]);
    }

    public function store(CheckInRequest $request, PerformanceCycle $cycle): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $this->authorize('create', [CheckIn::class, $employee]);
        abort_if($cycle->stage->value === 'completed', 422, 'That cycle is sealed — history gains no new notes.');

        unset($data['employee_id']);

        $checkIn = $cycle->checkIns()->create([
            ...$data,
            'employee_id' => $employee->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Check-in filed.',
            'check_in' => $this->present($checkIn->refresh()),
        ], Response::HTTP_CREATED);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CheckIn $checkIn): array
    {
        $checkIn->loadMissing('employee:id,employee_code,name');

        return [
            'id' => $checkIn->id,
            'cycle_id' => $checkIn->cycle_id,
            'employee_id' => $checkIn->employee_id,
            'employee' => $checkIn->employee ? [
                'id' => $checkIn->employee->id,
                'employee_code' => $checkIn->employee->employee_code,
                'name' => $checkIn->employee->displayName(),
            ] : null,
            'body' => $checkIn->body,
            'mood' => $checkIn->mood?->value,
            'blockers' => $checkIn->blockers,
            'needs_support' => $checkIn->needs_support,
            'created_at' => $checkIn->created_at?->toIso8601String(),
        ];
    }
}
