<?php

namespace App\Http\Controllers\Hrms\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Shift\RotationApplyRequest;
use App\Http\Requests\Hrms\Shift\RotationRequest;
use App\Models\Hrms\Attendance\AttendanceRotation;
use App\Services\Hrms\Shift\RosterService;
use App\Services\Hrms\Shift\RotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Shift/HRMS — rotation templates over HTTP, and applying one to people.
 */
class RotationController extends Controller
{
    public function __construct(
        private readonly RotationService $rotations,
        private readonly RosterService $rosters,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRotation::class);

        return response()->json(['rotations' => $this->rotations->rotations()->all()]);
    }

    public function store(RotationRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceRotation::class);

        return response()->json([
            'message' => 'Rotation created.',
            'rotation' => $this->rotations->create($request->validated(), $request->user()),
        ], Response::HTTP_CREATED);
    }

    public function update(RotationRequest $request, AttendanceRotation $rotation): JsonResponse
    {
        $this->authorize('update', $rotation);

        return response()->json([
            'message' => 'Rotation updated.',
            'rotation' => $this->rotations->update($rotation, $request->validated(), $request->user()),
        ]);
    }

    public function destroy(Request $request, AttendanceRotation $rotation): JsonResponse
    {
        $this->authorize('delete', $rotation);

        $this->rotations->delete($rotation, $request->user());

        return response()->json(['message' => 'Rotation deleted.']);
    }

    public function apply(RotationApplyRequest $request, AttendanceRotation $rotation): JsonResponse
    {
        $this->authorize('apply', $rotation);

        $data = $request->validated();
        $result = $this->rosters->applyRotation(
            $rotation,
            $data['employee_ids'],
            Carbon::parse($data['from'])->startOfDay(),
            Carbon::parse($data['to'])->startOfDay(),
            (int) ($data['offset'] ?? 0),
            $request->user(),
        );

        return response()->json(['message' => "Rotation applied to {$result['employees']} employee(s).", ...$result], Response::HTTP_CREATED);
    }
}
