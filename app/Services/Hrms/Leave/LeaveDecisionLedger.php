<?php

declare(strict_types=1);

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use Illuminate\Support\Carbon;

/**
 * Ledger postings and attendance regenerations for leave request lifecycle events.
 */
class LeaveDecisionLedger
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $calendar,
    ) {}

    public function postAvailed(LeaveRequest $request, ?User $actor): void
    {
        LeaveAdjustment::create($this->ledgerRow(
            $request,
            LeaveAdjustmentKind::Availed,
            -1 * abs((float) $request->total_days),
            $actor
        ));

        $this->balances->rebuildBalance($request->employee, $request->type, $this->calendar->leaveYearFor($request->from_date));
        $this->regenerateDates($request);
    }

    public function reversePosting(LeaveRequest $request, ?User $actor, string $note): void
    {
        LeaveAdjustment::create($this->ledgerRow(
            $request,
            LeaveAdjustmentKind::Adjustment,
            abs((float) $request->total_days),
            $actor,
            $note,
        ));

        $this->balances->rebuildBalance($request->employee, $request->type, $this->calendar->leaveYearFor($request->from_date));
        $this->regenerateDates($request);
    }

    public function postEncashment(LeaveRequest $request, ?User $actor, string $note): void
    {
        LeaveAdjustment::create($this->ledgerRow(
            $request,
            LeaveAdjustmentKind::Encashment,
            -1 * abs((float) $request->total_days),
            $actor,
            $note,
        ));

        $this->balances->rebuildBalance($request->employee, $request->type, $this->calendar->leaveYearFor($request->from_date));
        $this->regenerateDates($request);
    }

    public function regenerateDates(LeaveRequest $request): void
    {
        $request->days()->pluck('date')->each(fn ($date): mixed => $this->attendance->regenerate(
            $request->employee,
            $date instanceof Carbon ? $date->toDateString() : (string) $date,
        ));
    }

    /**
     * @return array{employee_id: int, leave_type_id: int, year: int, kind: string, quantity: float, reference_type: string, reference_id: int, note: string|null, actor_user_id: int|null, created_at: Carbon}
     */
    private function ledgerRow(LeaveRequest $request, LeaveAdjustmentKind $kind, float $quantity, ?User $actor, ?string $note = null): array
    {
        return [
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'year' => $this->calendar->leaveYearFor($request->from_date),
            'kind' => $kind->value,
            'quantity' => $quantity,
            'reference_type' => 'leave_request',
            'reference_id' => $request->id,
            'note' => $note,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ];
    }
}
