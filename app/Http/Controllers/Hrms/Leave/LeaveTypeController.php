<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveTypeRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\Leave\LeaveCatalogService;
use App\Services\Hrms\Leave\LeavePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Leave/HRMS — the leave-type catalogue over HTTP.
 *
 * Thin: validate, authorize, delegate, present. Reads are employee-open
 * (filing needs the catalogue); writes take `hrms.leave.manage`, and the
 * service — not this file — owns the system-row locks and the in-use
 * refusals.
 */
class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly LeaveCatalogService $catalog,
        private readonly LeavePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        return response()->json([
            'leave_types' => $this->catalog->types()
                ->map(fn (LeaveType $type): array => $this->presenter->type($type))
                ->all(),
        ]);
    }

    public function store(LeaveTypeRequest $request): JsonResponse
    {
        $this->authorize('create', LeaveType::class);

        $type = $this->catalog->createType($request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave type created.',
            'leave_type' => $this->presenter->type($type),
        ], Response::HTTP_CREATED);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorize('update', $leaveType);

        $type = $this->catalog->updateType($leaveType, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave type updated.',
            'leave_type' => $this->presenter->type($type),
        ]);
    }

    public function destroy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorize('delete', $leaveType);

        $this->catalog->deleteType($leaveType, $request->user());

        return response()->json(['message' => 'Leave type deleted.']);
    }
}
