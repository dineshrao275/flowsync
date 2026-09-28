<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\CaseTaskStatus;
use App\Enums\Hrms\DocumentRequestSource;
use App\Enums\Hrms\OnboardingCaseStatus;
use App\Enums\Hrms\TemplateTaskCategory;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\User;
use App\Services\Hrms\OnboardingService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — running an onboarding case.
 *
 * Split from {@see OnboardingService} because templates
 * (the reusable catalogue) and cases (one person’s run) are different
 * lifecycles sharing only a name: the template answers “what do we ask”,
 * this answers “what is still open for this hire”.
 */
class OnboardingCases
{
    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly CaseProgress $progress,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Start a case: materialise the template’s tasks and open the case.
     *
     * @throws ValidationException when the employee already has a case
     */
    public function createCase(Employee $employee, OnboardingTemplate $template, ?User $actor = null): OnboardingCase
    {
        if (OnboardingCase::where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages(['form' => 'This employee already has an onboarding case. Reopen that one instead of starting a parallel run.']);
        }

        if (! $template->is_active) {
            throw ValidationException::withMessages(['template_id' => 'That template has been retired. Pick a current one.']);
        }

        return DB::transaction(function () use ($employee, $template, $actor): OnboardingCase {
            $case = OnboardingCase::create([
                'employee_id' => $employee->id,
                'template_id' => $template->id,
                'status' => OnboardingCaseStatus::InProgress->value,
                'started_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $base = $employee->joining_date?->copy() ?? today();

            foreach ($template->tasks()->orderBy('position')->orderBy('id')->get() as $templateTask) {
                $task = OnboardingCaseTask::create([
                    'case_id' => $case->id,
                    'template_task_id' => $templateTask->id,
                    'title' => $templateTask->title,
                    'description' => $templateTask->description,
                    'category' => $templateTask->category->value,
                    'owner_scope' => $templateTask->owner_scope->value,
                    'owner_employee_id' => $this->ownerFor($templateTask->owner_scope->value, $employee),
                    'due_date' => $base->copy()->addDays($templateTask->due_offset_days),
                    'position' => $templateTask->position,
                ]);

                // The hinge: a document-category item is not a row on a
                // checklist, it is an ask for a file — so it raises one.
                if ($templateTask->category === TemplateTaskCategory::Document) {
                    $this->requests->requestFor(
                        $employee,
                        ['title' => $templateTask->title, 'due_date' => $task->due_date?->toDateString()],
                        DocumentRequestSource::Onboarding,
                        $case,
                        $actor,
                    );
                }
            }

            $this->audit->log($case, 'onboarding.case_started', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Mark one item done.
     *
     * @throws ValidationException on a closed item or an unauthorized actor
     */
    public function completeTask(OnboardingCaseTask $task, ?User $actor = null, ?string $note = null): OnboardingCaseTask
    {
        $this->requireOpen($task);
        $this->requireParticipant($task, $actor);

        return DB::transaction(function () use ($task, $actor, $note): OnboardingCaseTask {
            $task->update([
                'status' => CaseTaskStatus::Done->value,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'note' => $note,
            ]);
            $this->audit->log($task->case, 'onboarding.task_completed', null, $this->taskSnapshot($task->refresh()), $actor);

            return $task->refresh();
        });
    }

    /**
     * Waive one item, with the reason on record.
     *
     * A waived mandatory item needs the manage permission on top of the
     * reason: skipping something the template calls mandatory is a decision,
     * not a click, and decisions need both a name and a justification.
     *
     * @throws ValidationException on an empty reason, a closed item, or an unauthorized actor
     */
    public function waiveTask(OnboardingCaseTask $task, string $reason, ?User $actor = null): OnboardingCaseTask
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A waived item still needs its reason on record.']);
        }

        $this->requireOpen($task);

        if ($this->progress->isMandatory($task) && ! $this->canManage($actor)) {
            throw ValidationException::withMessages(['form' => 'Only someone with the onboarding-manage permission can waive a mandatory item.']);
        }

        $this->requireParticipant($task, $actor);

        return DB::transaction(function () use ($task, $reason, $actor): OnboardingCaseTask {
            $task->update([
                'status' => CaseTaskStatus::Waived->value,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'note' => $reason,
            ]);
            $this->audit->log($task->case, 'onboarding.task_waived', null, $this->taskSnapshot($task->refresh()), $actor);

            return $task->refresh();
        });
    }

    /**
     * Where this case stands — delegated, because the mandatory definition
     * and the photograph are one decision owned by {@see CaseProgress}.
     *
     * @return array{total: int, done: int, waived: int, open: int, mandatory_total: int, mandatory_open: int, percent: int}
     */
    public function progress(OnboardingCase $case): array
    {
        return $this->progress->for($case);
    }

    /**
     * Close a case whose mandatory items are all resolved.
     *
     * @throws ValidationException on a closed case or open mandatory items
     */
    public function complete(OnboardingCase $case, ?User $actor = null): OnboardingCase
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => "A {$case->status->value} case cannot be completed."]);
        }

        $progress = $this->progress($case);

        if ($progress['mandatory_open'] > 0) {
            $titles = $this->progress->blockingTitles($case);

            throw ValidationException::withMessages(['form' => 'Mandatory items are still open: '.implode(', ', $titles).'.']);
        }

        return DB::transaction(function () use ($case, $actor): OnboardingCase {
            $case->update([
                'status' => OnboardingCaseStatus::Completed->value,
                'completed_at' => now(),
            ]);
            $this->audit->log($case, 'onboarding.case_completed', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Cancel a run that will never finish — a hire who never showed up, a
     * duplicate case. The rows stay for the audit trail; only the status
     * moves, so a cancelled case reads as abandoned rather than done.
     *
     * @throws ValidationException on a closed case
     */
    public function cancel(OnboardingCase $case, ?User $actor = null): OnboardingCase
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => "A {$case->status->value} case cannot be cancelled."]);
        }

        return DB::transaction(function () use ($case, $actor): OnboardingCase {
            $case->update(['status' => OnboardingCaseStatus::Cancelled->value]);
            $this->audit->log($case, 'onboarding.case_cancelled', null, $this->snapshot($case->refresh()), $actor);

            return $case->refresh();
        });
    }

    /**
     * Who a materialised item belongs to. Employee-scoped items go to the
     * hire themselves; manager-scoped to their manager (which may not exist
     * yet — a null owner means “whoever manages them by the due date”);
     * hr/it items stay unassigned, because they belong to a pool, not a
     * person, and assigning them to whoever created the case would be a
     * guess about who does HR’s work.
     */
    private function ownerFor(string $scope, Employee $employee): ?int
    {
        return match ($scope) {
            'employee' => $employee->id,
            'manager' => $employee->manager_id,
            default => null,
        };
    }

    private function requireOpen(OnboardingCaseTask $task): void
    {
        if ($task->status->isResolved()) {
            throw ValidationException::withMessages(['form' => "This item is already {$task->status->value}."]);
        }

        if (! $task->case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => 'This case is closed.']);
        }
    }

    /**
     * The completer is the owner or someone with the manage permission. An
     * ownerless pool item (hr/it) needs manage: anybody completing those
     * without it is volunteering for work nobody assigned them.
     */
    private function requireParticipant(OnboardingCaseTask $task, ?User $actor): void
    {
        $isOwner = $actor !== null
            && $task->owner_employee_id !== null
            && $actor->employee?->id === (int) $task->owner_employee_id;

        if (! $isOwner && ! $this->canManage($actor)) {
            throw ValidationException::withMessages(['form' => 'Only the item’s owner or someone with the onboarding-manage permission can do this.']);
        }
    }

    private function canManage(?User $actor): bool
    {
        return $actor !== null && $actor->hasPermission('hrms.onboarding.manage');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OnboardingCase $case): array
    {
        return [
            'employee_id' => $case->employee_id,
            'template_id' => $case->template_id,
            'status' => $case->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskSnapshot(OnboardingCaseTask $task): array
    {
        return [
            'case_id' => $task->case_id,
            'title' => $task->title,
            'status' => $task->status->value,
        ];
    }
}
