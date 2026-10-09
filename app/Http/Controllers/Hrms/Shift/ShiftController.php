<?php

namespace App\Http\Controllers\Hrms\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Shift\ShiftAssignRequest;
use App\Http\Requests\Hrms\Shift\ShiftRequest;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Services\Hrms\Shift\ShiftPresenter;
use App\Services\Hrms\Shift\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shift/HRMS — the shift catalogue over HTTP: CRUD plus default-shift
 * assignment. Thin: validate, authorize, delegate, present.
 */
class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly ShiftPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AttendanceShift::class);

        return response()->json([
            'shifts' => $this->shifts->shifts()->map(fn (AttendanceShift $s): array => $this->presenter->shift($s))->all(),
        ]);
    }

    public function store(ShiftRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceShift::class);

        $shift = $this->shifts->create($request->validated(), $request->user());

        return response()->json(['message' => 'Shift created.', 'shift' => $this->presenter->shift($shift)], Response::HTTP_CREATED);
    }

    public function update(ShiftRequest $request, AttendanceShift $shift): JsonResponse
    {
        $this->authorize('update', $shift);

        $updated = $this->shifts->update($shift, $request->validated(), $request->user());

        return response()->json(['message' => 'Shift updated.', 'shift' => $this->presenter->shift($updated)]);
    }

    public function destroy(Request $request, AttendanceShift $shift): JsonResponse
    {
        $this->authorize('delete', $shift);

        $this->shifts->delete($shift, $request->user());

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function assign(ShiftAssignRequest $request, AttendanceShift $shift): JsonResponse
    {
        $this->authorize('update', $shift);

        $count = $this->shifts->assignDefault($shift, $request->validated()['employee_ids'], $request->user());

        return response()->json(['message' => "Default shift set for {$count} employee(s).", 'count' => $count]);
    }

    public function clear(ShiftAssignRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceShift::class);

        $count = $this->shifts->assignDefault(null, $request->validated()['employee_ids'], $request->user());

        return response()->json(['message' => "Default shift cleared for {$count} employee(s).", 'count' => $count]);
    }
}
