<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\OffboardingCaseStatus;
use App\Enums\Hrms\OffboardingReason;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\ExitClearance;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\User;
use App\Services\Hrms\Lifecycle\DocumentRequestService;
use App\Services\Hrms\Lifecycle\OffboardingChecklist;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Offboarding/HRMS — running an exit.
 *
 * Owns the checklist, the clearance counters, and the sign-off. Leave days
 * and expense money still read 0 until P6/P8 wire real balances in — the
 * columns are the right shape already, and a zero with a comment is honest
 * where a guessed number would be a fabricated clearance. Assets went
 * record-driven in P14: the counter below reads open handovers, not the
 * checklist's asset task (which stays as the human workflow step).
 */
class OffboardingService
{
    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly OffboardingChecklist $checklist,
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Open an exit run: the case, its checklist, its clearance row — and the
     * employee’s exit date, which comes from the last working day rather than
     * from whoever clicks first.
     *
     * @throws ValidationException on a second open case for the same employee
     */
    public function initiate(
        Employee $employee,
        Carbon|string $lastWorkingDay,
        OffboardingReason|string $reason,
        ?int $noticeDays = null,
        ?User $actor = null,
    ): OffboardingCase {
        $day = $lastWorkingDay instanceof Carbon ? $lastWorkingDay->copy()->startOfDay() : Carbon::parse((string) $lastWorkingDay)->startOfDay();
        $resolvedReason = $reason instanceof OffboardingReason ? $reason : OffboardingReason::tryFrom((string) $reason);

        if ($resolvedReason === null) {
            throw ValidationException::withMessages(['reason' => 'That is not a way an employment ends.']);
        }

        if ($noticeDays !== null && $noticeDays < 0) {
            throw ValidationException::withMessages(['notice_period_days' => 'A notice period cannot run backwards.']);
        }

        if (OffboardingCase::where('employee_id', $employee->id)->open()->exists()) {
            throw ValidationException::withMessages(['form' => 'This employee already has an open exit case. Finish or cancel that one first.']);
        }

        return DB::transaction(function () use ($employee, $day, $resolvedReason, $noticeDays, $actor): OffboardingCase {
            $case = OffboardingCase::create([
                'employee_id' => $employee->id,
                'last_working_day' => $day,
                'reason' => $resolvedReason->value,
                'status' => OffboardingCaseStatus::Initiated->value,
                'notice_period_days' => $noticeDays,
                'created_by' => $actor?->id,
            ]);

            $this->checklist->build($case, $actor);
            $clearance = $this->summary($case);

            // An exit that opens already blocked tells HR on day one: the
            // clearance names the blockers, and waiting for the refused
            // sign-off to say so wastes the whole notice period.
            if ($clearance->isBlocked()) {
                $this->notifications->offboardingClearancePending($case->refresh(), $actor);
            }

            // The exit date is a fact about the exit, not about who clicked:
            // whoever opens the case, the employee’s record carries the last
            // working day from that moment on.
            $employee->update(['exit_date' => $day->toDateString()]);

            $this->audit->log($case, 'offboarding.case_initiated', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Recompute the clearance counters and persist the photograph.
     *
     * Assets read open handovers (`active` assignments), not the
     * checklist's asset task: a completed checkbox is a claim, an open
     * handover is a fact, and the exit decides on facts. Leave days and
     * expense money still read 0 until P6/P8 wire real balances in.
     *
     * Persisting on every call — including reads — is deliberate: the row is
     * derived state, recomputed idempotently, so the screen never shows a
     * stale photograph and `clear()` always decides on fresh counters.
     */
    public function summary(OffboardingCase $case): ExitClearance
    {
        $openAssets = AssetAssignment::query()->where('employee_id', $case->employee_id)
            ->where('status', 'active')
            ->count();
        $openDocuments = DocumentRequest::query()
            ->where('employee_id', $case->employee_id)
            ->outstanding()
            ->count();

        $blocked = [];

        if ($openAssets > 0) {
            $blocked[] = "{$openAssets} asset".$this->plural($openAssets).' still to return.';
        }

        if ($openDocuments > 0) {
            $blocked[] = "{$openDocuments} document request".$this->plural($openDocuments).' still outstanding.';
        }

        $clearance = ExitClearance::updateOrCreate(
            ['case_id' => $case->id],
            [
                'pending_assets_count' => $openAssets,
                'pending_leave_encashment_days' => 0,
                'pending_expense_amount' => 0,
                'pending_documents_count' => $openDocuments,
                'blocked_reasons' => $blocked === [] ? null : $blocked,
            ],
        );

        return $clearance->refresh();
    }

    /**
     * Sign the exit off. Refuses while anything is outstanding — a clearance
     * signed over open items is a signature on a hope, and the 422 carries
     * the blockers so the screen can make them unmissable.
     *
     * @throws ValidationException while any counter is non-zero
     */
    public function clear(OffboardingCase $case, ?User $actor = null): ExitClearance
    {
        $clearance = $this->summary($case);

        if ($clearance->isBlocked()) {
            throw ValidationException::withMessages(['form' => 'This exit cannot be cleared yet: '.implode(' ', $clearance->blocked_reasons ?? [])]);
        }

        return DB::transaction(function () use ($case, $clearance, $actor): ExitClearance {
            $clearance->update([
                'dues_settled' => true,
                'cleared_by_user_id' => $actor?->id,
                'cleared_at' => now(),
            ]);
            $this->audit->log($case, 'offboarding.cleared', null, $this->snapshot($case->refresh()), $actor);

            return $clearance->refresh();
        });
    }

    /**
     * Close an exit whose clearance is signed.
     *
     * @throws ValidationException on a closed case or an unsigned clearance
     */
    public function complete(OffboardingCase $case, ?User $actor = null): OffboardingCase
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => "A {$case->status->value} case cannot be completed."]);
        }

        $clearance = $case->clearance;

        if ($clearance === null || $clearance->cleared_at === null) {
            throw ValidationException::withMessages(['form' => 'Clear the exit first — completion signs off a signed clearance, not an open one.']);
        }

        return DB::transaction(function () use ($case, $actor): OffboardingCase {
            $case->update(['status' => OffboardingCaseStatus::Completed->value]);
            $this->audit->log($case, 'offboarding.case_completed', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Cancel an exit that will not happen — a resignation withdrawn, a
     * duplicate case. The rows stay for the audit trail.
     *
     * @throws ValidationException on a closed case
     */
    public function cancel(OffboardingCase $case, ?User $actor = null): OffboardingCase
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => "A {$case->status->value} case cannot be cancelled."]);
        }

        return DB::transaction(function () use ($case, $actor): OffboardingCase {
            $case->update(['status' => OffboardingCaseStatus::Cancelled->value]);
            $this->audit->log($case, 'offboarding.case_cancelled', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Cases the viewer may list: everything for a directory reader, only
     * their own exits for anyone else.
     *
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, OffboardingCase>
     */
    public function casesFor(User $viewer, array $filters = []): Collection
    {
        $query = OffboardingCase::query()->with('employee')->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! $viewer->hasPermission('hrms.offboarding.view') && ! $viewer->hasPermission('hrms.offboarding.manage')) {
            $employeeId = Employee::where('user_id', $viewer->id)->value('id');

            $query->where('employee_id', $employeeId ?? -1);
        }

        return $query->get();
    }

    /**
     * Mark one checklist item done — delegated, like the build itself.
     *
     * @throws ValidationException on a closed item or case
     */
    public function completeTask(OffboardingCaseTask $task, ?User $actor = null, ?string $note = null): OffboardingCaseTask
    {
        return $this->checklist->completeTask($task, $actor, $note);
    }

    private function plural(int $count): string
    {
        return $count === 1 ? '' : 's';
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OffboardingCase $case): array
    {
        return [
            'employee_id' => $case->employee_id,
            'reason' => $case->reason->value,
            'status' => $case->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSnapshot(OffboardingCaseTask $task): array
    {
        return [
            'case_id' => $task->case_id,
            'title' => $task->title,
            'status' => $task->status->value,
        ];
    }
}
