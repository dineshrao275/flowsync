<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\RegularizationStatus;
use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance/HRMS — correcting a past day through the shared approval engine.
 *
 * A regularization never edits punches or the day row directly: approval
 * inserts `regularized`-source punches and recomputes the day, so the row
 * always equals its inputs. The approval chain is a single manager step
 * resolved through {@see ReportingLine} — the engine owns the state machine,
 * this service owns the attendance meaning of each transition.
 *
 * Window and semantics notes:
 * - `work_date` must be within `attendance.regularization_window_days`
 *   (default 7) and never in the future.
 * - `requested_first_in_at` is the corrected clock-in, `requested_punch_at`
 *   the corrected clock-out; both must fall on `work_date`.
 * - An employee with no resolvable manager auto-approves through the
 *   engine's own rule (audited as `approval.auto_approved`) and the
 *   correction applies immediately — a correction nobody can review must
 *   still be traceable, not stuck.
 */
class RegularizationService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ApprovalService $approvals,
        private readonly ReportingLine $reporting,
        private readonly ChainBuilder $chains,
        private readonly RegularizationNotifier $notifier,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{work_date: string, requested_punch_at?: string|null, requested_first_in_at?: string|null, reason: string}  $data
     *
     * @throws ValidationException outside the window, on a duplicate pending ask, or on mistimed corrections
     */
    public function request(Employee $employee, array $data, ?User $actor = null): AttendanceRegularizationRequest
    {
        $workDate = Carbon::parse((string) $data['work_date'])->startOfDay();
        $this->requireInWindow($workDate);

        $input = new RegularizationInput(
            workDate: $workDate,
            firstIn: $this->correctedTime($data['requested_first_in_at'] ?? null, $workDate, 'requested_first_in_at'),
            lastOut: $this->correctedTime($data['requested_punch_at'] ?? null, $workDate, 'requested_punch_at'),
            reason: trim((string) ($data['reason'] ?? '')),
        );

        if ($input->firstIn === null && $input->lastOut === null) {
            throw ValidationException::withMessages(['form' => 'A correction needs at least one corrected time.']);
        }

        $this->requireNoOpenAsk($employee, $workDate);

        return DB::transaction(fn (): AttendanceRegularizationRequest => $this->buildAsk($employee, $input, $actor));
    }

    /**
     * Persist the ask and open its manager step, applying immediately when
     * the chain settles approved at request time (no resolvable approver).
     */
    private function buildAsk(Employee $employee, RegularizationInput $input, ?User $actor): AttendanceRegularizationRequest
    {
        $day = $this->attendance->computeDay($employee, $input->workDate);

        $request = AttendanceRegularizationRequest::create([
            'attendance_day_id' => $day->id,
            'employee_id' => $employee->id,
            'work_date' => $input->workDate->toDateString(),
            'requested_punch_at' => $input->lastOut?->toDateTimeString(),
            'requested_first_in_at' => $input->firstIn?->toDateTimeString(),
            'reason' => $input->reason,
            'status' => RegularizationStatus::Pending->value,
        ]);

        $manager = $this->reporting->managerOf($employee);

        $approval = $this->approvals->request(
            $this->chains->stepsFor('regularization', $employee),
            $request,
            'regularize',
            'Regularization request',
            ['work_date' => $input->workDate->toDateString(), 'employee_id' => $employee->id],
            $actor,
            $employee->id,
            'regularization',
        );

        $request->update(['approval_id' => $approval->id]);
        $this->audit->log($request, 'attendance.regularization.requested', null, $this->snapshot($request), $actor);

        $this->notifier->requestRaised($request, $manager, $actor);
        $this->applyIfResolved($request, $actor);

        return $request->refresh();
    }

    /**
     * Approve the current step and apply the correction when the chain resolves.
     *
     * @throws ValidationException on a closed ask or when the actor is not the step's approver
     */
    public function approve(AttendanceRegularizationRequest $request, User $actor, ?string $note = null): AttendanceRegularizationRequest
    {
        $this->requireOpen($request);

        $this->approvals->approve($request->approval, $actor, $note);

        return DB::transaction(function () use ($request, $actor, $note): AttendanceRegularizationRequest {
            $request->update([
                'status' => RegularizationStatus::Approved->value,
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
                'decision_note' => $note,
            ]);

            $this->apply($request->refresh(), $actor);
            $this->audit->log($request, 'attendance.regularization.approved', ['status' => 'pending'], $this->snapshot($request->refresh()), $actor);
            $this->notifier->decided($request->refresh(), $actor);

            return $request->refresh();
        });
    }

    /**
     * Reject the current step. A reason is required — a refusal with no
     * reason gives the employee nothing to correct on resubmission.
     *
     * @throws ValidationException on a closed ask, an empty reason, or a non-approver
     */
    public function reject(AttendanceRegularizationRequest $request, User $actor, string $note): AttendanceRegularizationRequest
    {
        $this->requireOpen($request);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'A rejection needs a reason the employee can act on.']);
        }

        $this->approvals->reject($request->approval, $actor, $note);

        return DB::transaction(function () use ($request, $actor, $note): AttendanceRegularizationRequest {
            $request->update([
                'status' => RegularizationStatus::Rejected->value,
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
                'decision_note' => $note,
            ]);

            $this->audit->log($request, 'attendance.regularization.rejected', ['status' => 'pending'], $this->snapshot($request->refresh()), $actor);
            $this->notifier->decided($request->refresh(), $actor);

            return $request->refresh();
        });
    }

    /**
     * Apply an approved correction: superseding punches plus a recompute.
     *
     * Punches are inserted, never edited — the punch table is the negative
     * the day is developed from, and rewriting it would make the derived day
     * unverifiable. Both timestamps were validated onto `work_date` at
     * request time, so this re-derives nothing and trusts nothing.
     */
    private function apply(AttendanceRegularizationRequest $request, ?User $actor): void
    {
        $this->attendance->applyRegularization(
            $request->employee,
            $request->work_date,
            $request->requested_first_in_at,
            $request->requested_punch_at,
            $actor,
        );
    }

    /**
     * A chain with no resolvable approver settles approved at request time;
     * apply the correction in the same transaction rather than leaving an
     * approved ask whose day never changed.
     */
    private function applyIfResolved(AttendanceRegularizationRequest $request, ?User $actor): void
    {
        if ($request->approval->fresh()->status->value !== 'approved') {
            return;
        }

        $request->update([
            'status' => RegularizationStatus::Approved->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor?->id,
            'decision_note' => 'No approver was resolvable; auto-approved.',
        ]);

        $this->apply($request->refresh(), $actor);
    }

    /** @throws ValidationException */
    private function requireInWindow(Carbon $workDate): void
    {
        $today = today()->startOfDay();
        $maxDays = (int) HrmsSetting::current()->setting('attendance.regularization_window_days', 7);

        if ($workDate->greaterThan($today)) {
            throw ValidationException::withMessages(['work_date' => 'A correction cannot be requested for a future date.']);
        }

        if ($workDate->lessThan($today->copy()->subDays(max(1, $maxDays)))) {
            throw ValidationException::withMessages(['work_date' => "Corrections are accepted up to {$maxDays} days back."]);
        }
    }

    /** @throws ValidationException */
    private function requireNoOpenAsk(Employee $employee, Carbon $workDate): void
    {
        $open = AttendanceRegularizationRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', RegularizationStatus::Pending->value)
            ->whereDate('work_date', $workDate->toDateString())
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['form' => 'There is already a pending correction for this date.']);
        }
    }

    /** @throws ValidationException */
    private function requireOpen(AttendanceRegularizationRequest $request): void
    {
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['form' => 'This correction has already been decided.']);
        }

        if ($request->approval === null) {
            throw ValidationException::withMessages(['form' => 'This correction has no approval chain.']);
        }
    }

    /**
     * Parse a corrected time, pinning it to the day being corrected.
     *
     * @throws ValidationException when the time falls on another date
     */
    private function correctedTime(?string $value, Carbon $workDate, string $field): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $at = Carbon::parse($value);

        // `whereDate` elsewhere, exactness here: a correction stamped
        // Tuesday must not land on Monday's row, and a date-only string
        // parses to midnight — still that date, still valid.
        if ($at->toDateString() !== $workDate->toDateString()) {
            throw ValidationException::withMessages([$field => 'The corrected time must fall on the date being corrected.']);
        }

        return $at;
    }

    /** @return array<string, mixed> */
    private function snapshot(AttendanceRegularizationRequest $request): array
    {
        return Auditable::snapshot($request, ['employee_id', 'work_date', 'status', 'approval_id'], ['work_date' => 'date']);
    }
}
