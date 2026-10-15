<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveAdjustRequest;
use App\Http\Requests\Hrms\Leave\LeaveRolloverRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\Leave\LeaveManualAdjustment;
use App\Services\Hrms\Leave\LeaveYearRollover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Leave/HRMS — manual balance corrections, the ledger behind a balance, and
 * the year-end carry-forward/lapse run. Every route takes `hrms.leave.manage`.
 */
class LeaveAdjustmentController extends Controller
{
    public function __construct(
        private readonly LeaveManualAdjustment $adjustments,
        private readonly LeaveYearRollover $rollover,
    ) {}

    public function ledger(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['sometimes', 'nullable', 'integer', 'exists:leave_types,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $rows = $this->adjustments->ledger(
            Employee::findOrFail($filters['employee_id']),
            isset($filters['leave_type_id']) ? LeaveType::findOrFail($filters['leave_type_id']) : null,
            isset($filters['year']) ? (int) $filters['year'] : null,
        );

        return response()->json(['ledger' => $rows->map(fn (LeaveAdjustment $r): array => [
            'id' => $r->id,
            'leave_type' => $r->type?->name,
            'year' => $r->year,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'quantity' => (float) $r->quantity,
            'note' => $r->note,
            'actor' => $r->actor?->name,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function adjust(LeaveAdjustRequest $request): JsonResponse
    {
        $data = $request->validated();

        $row = $this->adjustments->adjust(
            Employee::findOrFail($data['employee_id']),
            LeaveType::findOrFail($data['leave_type_id']),
            (int) $data['year'],
            (float) $data['quantity'],
            $data['reason'],
            $request->user(),
        );

        return response()->json(['message' => 'Balance adjusted.', 'adjustment_id' => $row->id], Response::HTTP_CREATED);
    }

    public function rollover(LeaveRolloverRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->rollover->rollover(
            (int) $data['year'],
            isset($data['leave_type_id']) ? LeaveType::findOrFail($data['leave_type_id']) : null,
            (bool) ($data['dry_run'] ?? false),
            $request->user(),
        );

        return response()->json([
            'message' => $result['dry_run'] ? 'Dry run — nothing was written.' : "Rollover finished: {$result['carried']} day(s) carried, {$result['lapsed']} lapsed.",
            ...$result,
        ], $result['dry_run'] ? Response::HTTP_OK : Response::HTTP_CREATED);
    }
}
