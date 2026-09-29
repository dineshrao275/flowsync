<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — answering an ask.
 *
 * The deciding half of the lifecycle (asking lives in
 * `LeaveRequestService` — the P5 split rule). Approval posts the `availed`
 * row and flips the days via regeneration plus the merge; rejection
 * releases; cancellation reverses append-only; encashment leaves the
 * payable row P9 picks up.
 */
class LeaveRequestDecisions
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly ApprovalService $approvals,
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $calendar,
        private readonly NotificationService $notifications,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Approve the current step, finalizing when the chain itself resolves.
     *
     * Intermediate approvals only advance — the availed row, the rebuild
     * and the attendance flip land once, when the last step resolves. A
     * three-step chain therefore posts nothing until HR clears it, and the
     * ask reads `submitted` until then.
     *
     * @throws ValidationException on a decided ask or a non-approver
     */
    public function approve(LeaveRequest $request, User $actor, ?string $note = null): LeaveRequest
    {
        $this->requireDecidable($request);

        $from = $request->status->value;
        $approval = $this->approvals->approve($request->approval, $actor, $note);

        if ($approval->status !== ApprovalStatus::Approved) {
            return $request->refresh();
        }

        return $this->finalize($request, $actor, $from, $note);
    }

    /**
     * Post an approval the engine already resolved, from `approve()` or
     * from creation-time auto-approval (which would otherwise sit
     * `submitted` on a resolved chain). The requester hears through the
     * same `leaveDecided` path — a silent approval reads as vanishing.
     */
    public function finalize(LeaveRequest $request, ?User $actor, string $from, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $from, $note): LeaveRequest {
            $request->update([
                'status' => LeaveRequestStatus::Approved->value,
                'decided_at' => now(),
                'decided_by_user_id' => $actor?->id,
                'decision_note' => $note,
            ]);

            LeaveAdjustment::create($this->ledgerRow($request, LeaveAdjustmentKind::Availed, -1 * abs((float) $request->total_days), $actor));

            $this->balances->rebuildBalance($request->employee, $request->type, $this->calendar->leaveYearFor($request->from_date));
            $this->regenerateDates($request);

            $this->audit->log($request, 'leave.approved', ['status' => $from], $this->snapshot($request->refresh()), $actor);
            $this->notifications->leaveDecided($request->refresh(), $from, $actor);

            return $request->refresh();
        });
    }

    /**
     * Reject the current step. A reason is required — a refusal with no
     * reason gives the requester nothing to fix on resubmission.
     *
     * @throws ValidationException on a decided ask, an empty reason, or a non-approver
     */
    public function reject(LeaveRequest $request, User $actor, string $note): LeaveRequest
    {
        $this->requireDecidable($request);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'A rejection needs a reason the requester can act on.']);
        }

        $from = $request->status->value;
        $this->approvals->reject($request->approval, $actor, $note);

        return DB::transaction(function () use ($request, $actor, $note, $from): LeaveRequest {
            $this->stampDecision($request, LeaveRequestStatus::Rejected, $actor, $note);

            // Nothing posted, nothing to release: the availed row is written
            // on approval, never on submission.
            $this->audit->log($request, 'leave.rejected', ['status' => $from], $this->snapshot($request->refresh()), $actor);
            $this->notifications->leaveDecided($request->refresh(), $from, $actor);

            return $request->refresh();
        });
    }

    /**
     * Withdraw an ask. Cancellation is the requester's own hand while the
     * chain is open (the engine enforces it); a decided approval needs no
     * chain action, only ledger repair. HR stops someone else's ask by
     * rejecting it, not by cancelling it.
     *
     * @throws ValidationException on a terminal ask
     */
    public function cancelRequest(LeaveRequest $request, ?User $actor, ?string $reason = null): LeaveRequest
    {
        // Approved stays cancellable: the time was posted, and cancelling
        // returns it. Only terminal refusals (rejected, expired) and an
        // already-cancelled ask refuse — `isTerminal()` would wrongly refuse
        // approved asks, whose whole point here is to be undone.
        if (! in_array($request->status, [LeaveRequestStatus::Draft, LeaveRequestStatus::Submitted, LeaveRequestStatus::Pending, LeaveRequestStatus::Approved], true)) {
            throw ValidationException::withMessages(['form' => 'This ask can no longer be cancelled.']);
        }

        $wasApproved = $request->status === LeaveRequestStatus::Approved;

        if ($request->approval !== null && $request->approval->isOpen()) {
            // The engine only lets the requester cancel: without an identity
            // there is nobody to check, and HR stops someone else's ask by
            // rejecting it, not by cancelling it.
            if ($actor === null) {
                throw ValidationException::withMessages(['form' => 'Cancelling an open ask needs a login.']);
            }

            $this->approvals->cancel($request->approval, $actor);
        }

        return DB::transaction(function () use ($request, $actor, $reason, $wasApproved): LeaveRequest {
            $request->update([
                'status' => LeaveRequestStatus::Cancelled->value,
                'cancel_reason' => $reason,
                'decided_at' => now(),
                'decided_by_user_id' => $actor?->id,
            ]);

            if ($wasApproved) {
                $this->reversePosting($request, $actor, 'Cancellation of approved leave — time returned.');
            }

            $this->audit->log($request, 'leave.cancelled', ['status' => $wasApproved ? 'approved' : 'pending'], $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /**
     * Convert approved time into a payable: return the posted days, then
     * debit them as encashed. The encashed row is the payable reference P9
     * picks up — this returns the ask, never a payroll handle.
     *
     * @throws ValidationException unless approved under an encashable type
     */
    public function encash(LeaveRequest $request, ?User $actor = null): LeaveRequest
    {
        if ($request->status !== LeaveRequestStatus::Approved) {
            throw ValidationException::withMessages(['form' => 'Only approved leave can be encashed.']);
        }

        if (! $request->type->encashable) {
            throw ValidationException::withMessages(['form' => 'This leave type cannot be encashed.']);
        }

        return DB::transaction(function () use ($request, $actor): LeaveRequest {
            $this->reversePosting($request, $actor, 'Encashment — posted time returned for payout.');

            LeaveAdjustment::create($this->ledgerRow(
                $request,
                LeaveAdjustmentKind::Encashment,
                -1 * abs((float) $request->total_days),
                $actor,
                'Payable — picked up by the payroll run.',
            ));

            $this->balances->rebuildBalance($request->employee, $request->type, $this->calendar->leaveYearFor($request->from_date));
            $this->regenerateDates($request);

            $this->audit->log($request, 'leave.encashed', ['status' => 'approved'], $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /** @throws ValidationException on a decided ask or a missing chain */
    private function requireDecidable(LeaveRequest $request): void
    {
        if ($request->status !== LeaveRequestStatus::Submitted && $request->status !== LeaveRequestStatus::Pending) {
            throw ValidationException::withMessages(['form' => 'This ask has already been decided.']);
        }

        if ($request->approval === null) {
            throw ValidationException::withMessages(['form' => 'This ask has no approval chain.']);
        }
    }

    /**
     * Stamp who decided what: approve and reject share the shape; cancel
     * writes its own row because it keeps the approver's note and adds a
     * cancel reason instead.
     */
    private function stampDecision(LeaveRequest $request, LeaveRequestStatus $status, User $actor, ?string $note): void
    {
        $request->update([
            'status' => $status->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor->id,
            'decision_note' => $note,
        ]);
    }

    /**
     * Return posted time with an offsetting row, then rebuild and
     * regenerate: the shared repair half of cancellation and encashment.
     * The availed row stays — the ledger is append-only, so history keeps
     * both the posting and its return.
     */
    private function reversePosting(LeaveRequest $request, ?User $actor, string $note): void
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

    /**
     * One ledger row against an ask: every posting names the request as its
     * reference, so the ledger tells which ask moved each day.
     *
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

    /**
     * Recompute every date the ask covers, so approval, cancellation and
     * encashment all land on the attendance the merge reads.
     */
    private function regenerateDates(LeaveRequest $request): void
    {
        // pluck() skips casts, so the SQLite time part rides along — parsing
        // normalizes it, and the recompute is idempotent either way.
        $request->days()->pluck('date')->each(fn ($date): mixed => $this->attendance->regenerate(
            $request->employee,
            $date instanceof Carbon ? $date->toDateString() : (string) $date,
        ));
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveRequest $request): array
    {
        return [
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'total_days' => (float) $request->total_days,
            'status' => $request->status->value,
            'approval_id' => $request->approval_id,
        ];
    }
}
