<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveBlackoutRequest;
use App\Models\Hrms\Leave\LeaveBlackout;
use App\Services\Hrms\Leave\LeaveBlackoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Leave/HRMS — blackout windows over HTTP. Reads are open to anyone in the
 * leave module (an employee should be able to see why an ask is refused);
 * writes take `hrms.leave.manage` at the route.
 */
class LeaveBlackoutController extends Controller
{
    public function __construct(private readonly LeaveBlackoutService $blackouts) {}

    public function index(): JsonResponse
    {
        return response()->json(['blackouts' => $this->blackouts->all()->map(fn (LeaveBlackout $b): array => $this->present($b))->all()]);
    }

    public function store(LeaveBlackoutRequest $request): JsonResponse
    {
        $b = $this->blackouts->create($request->validated(), $request->user());

        return response()->json(['message' => 'Blackout created.', 'blackout' => $this->present($b)], Response::HTTP_CREATED);
    }

    public function update(LeaveBlackoutRequest $request, LeaveBlackout $blackout): JsonResponse
    {
        return response()->json([
            'message' => 'Blackout updated.',
            'blackout' => $this->present($this->blackouts->update($blackout, $request->validated(), $request->user())),
        ]);
    }

    public function destroy(Request $request, LeaveBlackout $blackout): JsonResponse
    {
        $this->blackouts->delete($blackout, $request->user());

        return response()->json(['message' => 'Blackout deleted.']);
    }

    /** @return array<string, mixed> */
    private function present(LeaveBlackout $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'from_date' => $b->from_date->toDateString(),
            'to_date' => $b->to_date->toDateString(),
            'leave_type_id' => $b->leave_type_id,
            'leave_type' => $b->type?->name,
            'department_id' => $b->department_id,
            'department' => $b->department?->name,
            'reason' => $b->reason,
            'is_active' => $b->is_active,
        ];
    }
}
