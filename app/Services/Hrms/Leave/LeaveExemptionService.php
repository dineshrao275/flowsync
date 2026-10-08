<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveExemptionRequest;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — statutory exemption asks.
 *
 * Exemptions consume no balance and post no ledger rows; they are a
 * jurisdiction trail (who was excused, for which span, decided by whom),
 * which is why they skip the balance engine entirely and still walk the
 * leave approval chain. The P6.4 policy layer wires the jurisdiction gate;
 * this file owns the state machine.
 */
class LeaveExemptionService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly LeaveApprovalRouting $routing,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{leave_type_id: int, from_date: string, to_date: string, days: float|int|string, reason: string, fiscal_year?: int|null}  $data
     *
     * @throws ValidationException on a backward range
     */
    public function requestFor(Employee $employee, array $data, ?User $actor = null): LeaveExemptionRequest
    {
        $from = Carbon::parse((string) $data['from_date'])->startOfDay();
        $to = Carbon::parse((string) $data['to_date'])->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to_date' => 'The range ends before it starts.']);
        }

        return DB::transaction(function () use ($employee, $data, $actor, $from, $to): LeaveExemptionRequest {
            $request = LeaveExemptionRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => (int) $data['leave_type_id'],
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'days' => $data['days'],
                'reason' => trim((string) $data['reason']),
                'status' => LeaveRequestStatus::Pending->value,
                'fiscal_year' => (int) ($data['fiscal_year'] ?? $from->year),
            ]);

            $approval = $this->approvals->request(
                $this->routing->stepsFor($employee),
                $request,
                'leave.exemption',
                'Leave exemption request',
                ['work_dates' => $from->toDateString().' to '.$to->toDateString(), 'days' => (float) $data['days']],
                $actor,
                $employee->id,
            );

            $request->update(['approval_id' => $approval->id]);
            $this->audit->log($request, 'leave.exemption_requested', null, $this->snapshot($request), $actor);

            return $request->refresh();
        });
    }

    /**
     * Decide an exemption: approve flips the status, reject needs a reason.
     * Either way nothing posts — exemptions never touch the ledger.
     *
     * @throws ValidationException on a decided ask, an empty rejection reason, or a non-decider
     */
    public function decide(LeaveExemptionRequest $request, User $actor, string $verdict, ?string $note = null): LeaveExemptionRequest
    {
        if ($request->status->isTerminal() || $request->approval === null) {
            throw ValidationException::withMessages(['form' => 'This ask has already been decided.']);
        }

        if (! in_array($verdict, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'A verdict is approve or reject.']);
        }

        if ($verdict === 'reject' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'A rejection needs a reason the requester can act on.']);
        }

        if ($verdict === 'approve') {
            $approval = $this->approvals->approve($request->approval, $actor, $note);

            // Intermediate approvals only advance (the P6.3 lesson): the
            // verdict lands when the chain itself resolves.
            if ($approval->status !== ApprovalStatus::Approved) {
                return $request->refresh();
            }
        } else {
            $this->approvals->reject($request->approval, $actor, $note);
        }

        return DB::transaction(function () use ($request, $actor, $verdict): LeaveExemptionRequest {
            $request->update([
                'status' => $verdict === 'approve' ? LeaveRequestStatus::Approved->value : LeaveRequestStatus::Rejected->value,
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
            ]);

            $this->audit->log(
                $request,
                $verdict === 'approve' ? 'leave.exemption_approved' : 'leave.exemption_rejected',
                ['status' => 'pending'],
                $this->snapshot($request->refresh()),
                $actor,
            );

            return $request->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveExemptionRequest $request): array
    {
        return [
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'days' => (float) $request->days,
            'status' => $request->status->value,
            'approval_id' => $request->approval_id,
        ];
    }
}
