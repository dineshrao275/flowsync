<?php

namespace App\Http\Controllers\Hrms\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Shift\RosterAssignRequest;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Shift\RosterService;
use App\Services\Hrms\Shift\ShiftPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Shift/HRMS — rosters over HTTP: the window grid, my own rows, assigning
 * a person to a shift and removing a row. Thin: validate, authorize,
 * delegate, present.
 */
class RosterController extends Controller
{
    public function __construct(
        private readonly RosterService $rosters,
        private readonly ShiftPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRoster::class);

        [$from, $to] = $this->window($request);
        $ids = $request->filled('employee_id') ? [(int) $request->query('employee_id')] : null;

        return response()->json([
            'rosters' => $this->rosters->between($from, $to, $ids)
                ->map(fn (AttendanceRoster $r): array => $this->presenter->roster($r))->all(),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();

        return response()->json([
            'rosters' => $employee === null ? [] : $this->rosters->between($from, $to, [$employee->id])
                ->map(fn (AttendanceRoster $r): array => $this->presenter->roster($r))->all(),
        ]);
    }

    public function store(RosterAssignRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceRoster::class);

        $data = $request->validated();
        $roster = $this->rosters->assign(
            Employee::query()->findOrFail($data['employee_id']),
            $data['shift_id'] ?? null,
            Carbon::parse($data['effective_from'])->startOfDay(),
            isset($data['effective_to']) ? Carbon::parse($data['effective_to'])->startOfDay() : null,
            $data['weekly_offs'] ?? null,
            (bool) ($data['is_flexible'] ?? false),
            $request->user(),
        );

        return response()->json(['message' => 'Roster saved.', 'roster' => $this->presenter->roster($roster)], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, AttendanceRoster $roster): JsonResponse
    {
        $this->authorize('delete', $roster);

        $this->rosters->remove($roster, $request->user());

        return response()->json(['message' => 'Roster row removed.']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfWeek();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : $from->copy()->addDays(13);

        return [$from, $to];
    }
}
