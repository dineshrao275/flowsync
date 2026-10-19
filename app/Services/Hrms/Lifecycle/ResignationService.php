<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\OffboardingReason;
use App\Enums\Hrms\ResignationStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\ResignationRequest;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\OffboardingService;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — an employee's own resignation.
 *
 * Filing opens an approval (domain `resignation`: reporting manager, then HR unless the
 * requester is HR). When the chain approves, the exit case opens itself with the resignation's
 * last working day, so nothing is retyped and the exit date is set by the same rule as every other
 * exit. Withdrawing is the employee's own hand while the chain is open; once approved the exit case
 * is HR's to cancel, not the employee's.
 */
class ResignationService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ChainBuilder $chains,
        private readonly OffboardingService $offboarding,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{requested_last_day: string, reason: string, notice_period_days?: int|null}  $data
     *
     * @throws ValidationException on a past date, an open resignation, or an exit already in progress
     */
    public function submit(Employee $employee, array $data, ?User $actor = null): ResignationRequest
    {
        $day = Carbon::parse($data['requested_last_day'])->startOfDay();

        if ($day->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['requested_last_day' => __('The last working day cannot be in the past.')]);
        }

        if (trim((string) $data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => __('Say why you are resigning.')]);
        }

        if (ResignationRequest::query()->where('employee_id', $employee->id)->where('status', ResignationStatus::Pending->value)->exists()) {
            throw ValidationException::withMessages(['form' => __('You already have a resignation waiting for a decision.')]);
        }

        if (OffboardingCase::query()->where('employee_id', $employee->id)->open()->exists()) {
            throw ValidationException::withMessages(['form' => __('An exit is already in progress for this employee.')]);
        }

        return DB::transaction(function () use ($employee, $data, $actor, $day): ResignationRequest {
            $request = ResignationRequest::create([
                'employee_id' => $employee->id,
                'requested_last_day' => $day->toDateString(),
                'notice_period_days' => $data['notice_period_days'] ?? null,
                'reason' => trim((string) $data['reason']),
                'status' => ResignationStatus::Pending->value,
            ]);

            $approval = $this->approvals->request(
                $this->chains->stepsFor('resignation', $employee),
                $request,
                'resignation.request',
                'Resignation',
                ['last_working_day' => $day->toDateString()],
                $actor,
                $employee->id,
                'resignation',
            );
            $request->update(['approval_id' => $approval->id]);

            $this->audit->log($request, 'resignation.submitted', null, $this->snapshot($request->refresh()), $actor);

            // A chain with nobody to ask settles approved at filing time.
            return $approval->status === ApprovalStatus::Approved ? $this->finalize($request, $actor) : $request->refresh();
        });
    }

    /** @throws ValidationException on a decided request or a non-approver */
    public function approve(ResignationRequest $request, User $actor, ?string $note = null): ResignationRequest
    {
        $this->requireOpen($request);

        $approval = $this->approvals->approve($request->approval, $actor, $note);

        return $approval->status === ApprovalStatus::Approved
            ? $this->finalize($request, $actor, $note)
            : $request->refresh();
    }

    /** @throws ValidationException on a decided request, an empty reason, or a non-approver */
    public function reject(ResignationRequest $request, User $actor, string $note): ResignationRequest
    {
        $this->requireOpen($request);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => __('A rejection needs a reason the employee can act on.')]);
        }

        $this->approvals->reject($request->approval, $actor, $note);

        $request->update([
            'status' => ResignationStatus::Rejected->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor->id,
            'decision_note' => mb_substr(trim($note), 0, 500),
        ]);
        $this->audit->log($request, 'resignation.rejected', ['status' => 'pending'], $this->snapshot($request->refresh()), $actor);

        return $request->refresh();
    }

    /** The employee takes it back while the chain is still open. */
    public function withdraw(ResignationRequest $request, User $actor): ResignationRequest
    {
        $this->requireOpen($request);

        $this->approvals->cancel($request->approval, $actor);

        $request->update(['status' => ResignationStatus::Withdrawn->value, 'decided_at' => now(), 'decided_by_user_id' => $actor->id]);
        $this->audit->log($request, 'resignation.withdrawn', ['status' => 'pending'], $this->snapshot($request->refresh()), $actor);

        return $request->refresh();
    }

    private function finalize(ResignationRequest $request, ?User $actor, ?string $note = null): ResignationRequest
    {
        $employee = $request->employee;
        $existing = OffboardingCase::query()->where('employee_id', $employee->id)->open()->first();

        // If HR opened an exit while the chain was running, link it rather than open a second one.
        $case = $existing ?? $this->offboarding->initiate(
            $employee,
            $request->requested_last_day,
            OffboardingReason::Resigned,
            $request->notice_period_days,
            $actor,
        );

        $request->update([
            'status' => ResignationStatus::Approved->value,
            'decided_at' => now(),
            'decided_by_user_id' => $actor?->id,
            'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            'offboarding_case_id' => $case->id,
        ]);
        $this->audit->log($request, 'resignation.approved', ['status' => 'pending'], $this->snapshot($request->refresh()), $actor);

        return $request->refresh();
    }

    private function requireOpen(ResignationRequest $request): void
    {
        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages(['form' => __('This resignation has already been decided.')]);
        }

        if ($request->approval === null) {
            throw ValidationException::withMessages(['form' => __('This resignation has no approval chain.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(ResignationRequest $request): array
    {
        return Auditable::snapshot($request, ['employee_id', 'requested_last_day', 'status', 'approval_id', 'offboarding_case_id']);
    }
}
