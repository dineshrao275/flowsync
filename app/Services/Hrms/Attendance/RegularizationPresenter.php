<?php

namespace App\Services\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;

/**
 * Attendance/HRMS — what a correction ask looks like over HTTP.
 *
 * One shape for the request, the queue and the detail screen, next to the
 * punch/day shapes in {@see DayPresenter}: the queue and the detail must
 * never drift into two dialects for the same row.
 */
class RegularizationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(AttendanceRegularizationRequest $request): array
    {
        $request->loadMissing(['employee:id,name,employee_code', 'approval:id,status,current_step', 'decider:id,name']);

        return [
            'id' => $request->id,
            'employee' => $request->employee === null ? null : [
                'id' => $request->employee->id,
                'name' => $request->employee->name,
                'employee_code' => $request->employee->employee_code,
            ],
            'work_date' => $request->work_date->toDateString(),
            'requested_first_in_at' => $request->requested_first_in_at?->toISOString(),
            'requested_punch_at' => $request->requested_punch_at?->toISOString(),
            'reason' => $request->reason,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'approval' => $request->approval === null ? null : [
                'id' => $request->approval->id,
                'status' => $request->approval->status->value,
                'current_step' => $request->approval->current_step,
            ],
            'decided_at' => $request->decided_at?->toISOString(),
            'decided_by' => $request->decider === null ? null : [
                'id' => $request->decider->id,
                'name' => $request->decider->name,
            ],
            'decision_note' => $request->decision_note,
            'created_at' => $request->created_at?->toISOString(),
        ];
    }
}
