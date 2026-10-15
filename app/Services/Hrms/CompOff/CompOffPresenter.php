<?php

namespace App\Services\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\CompOff\CompOffRequest;

/**
 * CompOff/HRMS — what banked time and redemption asks look like over HTTP.
 *
 * One shape per resource, defined once (D2.16.4). Minutes stay minutes —
 * the client formats durations, the server never ships pre-formatted
 * strings it cannot sort by.
 */
class CompOffPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function credit(CompOffCredit $credit): array
    {
        return [
            'id' => $credit->id,
            'employee_id' => $credit->employee_id,
            'work_date' => $credit->work_date->toDateString(),
            'source_type' => $credit->source_type->value,
            'source_label' => $credit->source_type->label(),
            'minutes' => $credit->minutes,
            'expiry_date' => $credit->expiry_date?->toDateString(),
            'expired' => $credit->isExpired(),
            'note' => $credit->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(CompOffRequest $request): array
    {
        $request->loadMissing(['employee:id,name,employee_code', 'days', 'approval:id,status,current_step', 'decider:id,name']);

        return [
            'id' => $request->id,
            'employee' => $request->employee === null ? null : [
                'id' => $request->employee->id,
                'name' => $request->employee->name,
                'employee_code' => $request->employee->employee_code,
            ],
            'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(),
            'total_minutes' => $request->total_minutes,
            'reason' => $request->reason,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'approval' => $request->approval === null ? null : [
                'id' => $request->approval->id,
                'status' => $request->approval->status->value,
                'current_step' => $request->approval->current_step,
            ],
            'days' => $request->days->map(fn ($day): array => [
                'date' => $day->date->toDateString(),
                'minutes' => $day->minutes,
            ])->all(),
            'decided_at' => $request->decided_at?->toISOString(),
            'decided_by' => $request->decider === null ? null : [
                'id' => $request->decider->id,
                'name' => $request->decider->name,
            ],
            'created_at' => $request->created_at?->toISOString(),
        ];
    }
}
