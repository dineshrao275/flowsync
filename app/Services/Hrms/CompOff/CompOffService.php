<?php

namespace App\Services\Hrms\CompOff;

use App\Enums\Hrms\CompOffRequestStatus;
use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CompOff/HRMS — redeeming banked time.
 *
 * The spending half of comp-off (banking lives in `CompOffCredits` — the
 * P5 split rule). A redemption debits the same standard-day minutes a
 * credit banks, week-offs stay out of the charged total like leave, and
 * the chain is a single manager step: the balance is derived, so approval
 * posts nothing to reverse later and cancellation restores by status
 * alone.
 */
class CompOffService
{
    public function __construct(
        private readonly CompOffCredits $credits,
        private readonly ApprovalService $approvals,
        private readonly ChainBuilder $chains,
        private readonly NotificationService $notifications,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{from_date: string, to_date: string, reason: string}  $data
     *
     * @throws ValidationException on a bad window, a dayless range, an overlap, or a shortfall
     */
    public function request(Employee $employee, array $data, ?User $actor = null): CompOffRequest
    {
        $from = Carbon::parse((string) $data['from_date'])->startOfDay();
        $to = Carbon::parse((string) $data['to_date'])->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to_date' => 'The range ends before it starts.']);
        }

        $split = $this->credits->chargeableDays($employee, $from, $to);
        $total = count($split) * CompOffCredits::DAY_MINUTES;

        if ($total <= 0) {
            throw ValidationException::withMessages(['form' => 'The range holds no chargeable day — only week-offs and holidays.']);
        }

        $this->checkOverlap($employee, $from, $to);

        $available = $this->availableMinutes($employee, $from, $to);

        if ($available < $total) {
            throw ValidationException::withMessages([
                'form' => "Available {$available} minutes, short by ".($total - $available).'.',
            ]);
        }

        return DB::transaction(function () use ($employee, $data, $actor, $from, $to, $total, $split): CompOffRequest {
            $ask = CompOffRequest::create([
                'employee_id' => $employee->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'total_minutes' => $total,
                'reason' => trim((string) $data['reason']),
                'status' => CompOffRequestStatus::Submitted->value,
            ]);

            foreach ($split as $date) {
                $ask->days()->create(['date' => $date, 'minutes' => CompOffCredits::DAY_MINUTES]);
            }

            $approval = $this->approvals->request(
                $this->chains->stepsFor('comp_off', $employee, ['days' => count($split)]),
                $ask,
                'comp_off.request',
                'Comp-off request',
                ['work_dates' => $from->toDateString().' to '.$to->toDateString(), 'total_minutes' => $total],
                $actor,
                $employee->id,
                'comp_off',
            );

            $ask->update(['approval_id' => $approval->id]);
            $this->audit->log($ask, 'comp_off.requested', null, $this->snapshot($ask), $actor);
            $this->notifications->compOffRequested($ask->refresh(), $actor);

            return $ask->refresh();
        });
    }

    /**
     * @throws ValidationException on a decided ask or a non-approver
     */
    public function approve(CompOffRequest $request, User $actor, ?string $note = null): CompOffRequest
    {
        $this->requireDecidable($request);

        // A chain with no resolvable approver settles approved at request
        // time: finalize without touching the engine, which would refuse an
        // already-resolved approval.
        if (! $request->approval->isOpen()) {
            return $this->finalize($request, $actor, $request->status->value);
        }

        $this->approvals->approve($request->approval, $actor, $note);

        return $this->finalize($request, $actor, $request->status->value);
    }

    /**
     * @throws ValidationException on a decided ask, an empty reason, or a non-approver
     */
    public function reject(CompOffRequest $request, User $actor, string $note): CompOffRequest
    {
        $this->requireDecidable($request);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'A rejection needs a reason the requester can act on.']);
        }

        $this->approvals->reject($request->approval, $actor, $note);

        $from = $request->status->value;

        $request->update([
            'status' => CompOffRequestStatus::Rejected->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor->id,
        ]);

        $this->audit->log($request, 'comp_off.rejected', ['status' => $from], $this->snapshot($request->refresh()), $actor);
        $this->notifications->compOffDecided($request->refresh(), $from, $actor);

        return $request->refresh();
    }

    /**
     * Withdraw an ask. Approved stays cancellable — the derived balance
     * restores itself the moment the status flips, with no ledger repair
     * to forget. Cancellation is the requester's own hand while the chain
     * is open (the engine enforces it).
     *
     * @throws ValidationException on a rejected or cancelled ask
     */
    public function cancelRequest(CompOffRequest $request, ?User $actor = null): CompOffRequest
    {
        if (in_array($request->status, [CompOffRequestStatus::Rejected, CompOffRequestStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['form' => 'This ask can no longer be cancelled.']);
        }

        if ($request->approval !== null && $request->approval->isOpen()) {
            if ($actor === null) {
                throw ValidationException::withMessages(['form' => 'Cancelling an open ask needs a login.']);
            }

            $this->approvals->cancel($request->approval, $actor);
        }

        $request->update([
            'status' => CompOffRequestStatus::Cancelled->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor?->id,
        ]);

        $this->audit->log($request, 'comp_off.cancelled', null, $this->snapshot($request->refresh()), $actor);

        return $request->refresh();
    }

    /**
     * Free minutes in a window: the derived balance minus live asks holding
     * some of it. Approved asks already sit inside the balance, so only
     * submitted and pending reserve.
     */
    private function availableMinutes(Employee $employee, Carbon $from, Carbon $to): int
    {
        $reserved = (int) CompOffRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [CompOffRequestStatus::Submitted->value, CompOffRequestStatus::Pending->value])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->sum('total_minutes');

        return $this->credits->balance($employee) - $reserved;
    }

    /**
     * @throws ValidationException on any live ask over the window
     */
    private function checkOverlap(Employee $employee, Carbon $from, Carbon $to): void
    {
        $clash = CompOffRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [
                CompOffRequestStatus::Submitted->value,
                CompOffRequestStatus::Pending->value,
                CompOffRequestStatus::Approved->value,
            ])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['form' => 'An ask already covers part of this range.']);
        }
    }

    /**
     * @throws ValidationException on a decided ask or a missing chain */
    private function requireDecidable(CompOffRequest $request): void
    {
        if ($request->status !== CompOffRequestStatus::Submitted && $request->status !== CompOffRequestStatus::Pending) {
            throw ValidationException::withMessages(['form' => 'This ask has already been decided.']);
        }

        if ($request->approval === null) {
            throw ValidationException::withMessages(['form' => 'This ask has no approval chain.']);
        }
    }

    private function finalize(CompOffRequest $request, ?User $actor, string $from = 'submitted'): CompOffRequest
    {
        $request->update([
            'status' => CompOffRequestStatus::Approved->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor?->id,
        ]);

        $this->audit->log($request, 'comp_off.approved', ['status' => $from], $this->snapshot($request->refresh()), $actor);
        $this->notifications->compOffDecided($request->refresh(), $from, $actor);

        return $request->refresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(CompOffRequest $request): array
    {
        return Auditable::snapshot($request, ['employee_id', 'total_minutes', 'status', 'approval_id']);
    }
}
