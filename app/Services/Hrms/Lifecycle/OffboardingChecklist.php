<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\CaseTaskStatus;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\User;
use App\Services\Hrms\OffboardingService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — the exit checklist.
 *
 * Split from {@see OffboardingService} because the
 * checklist items and the clearance sign-off are different concerns: this
 * owns what the exit asks for and what got done, while the service owns
 * whether the exit may close. Note the asymmetry with onboarding — these
 * items are built from a fixed list, not a template, because every exit
 * needs the same five things and a catalogue nobody customises is a
 * catalogue nobody maintains.
 */
class OffboardingChecklist
{
    public function __construct(
        private readonly DocumentRequestService $requests,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * The five items every exit needs, in checklist order.
     *
     * Skips when the case already has tasks: initiate() calls this once, and
     * a second call must repair nothing and duplicate nothing — a checklist
     * that doubles itself on re-run is how an exit ends up with two “return
     * laptop” rows and one returned laptop.
     *
     * Asset and expense linkage stays null until P14/P8 exist to fill it; the
     * rows are still real checklist items with owners and due dates, so the
     * exit is trackable today and linkable tomorrow without a migration.
     */
    public function build(OffboardingCase $case, ?User $actor = null): OffboardingCase
    {
        if ($case->tasks()->exists()) {
            return $case;
        }

        $items = [
            ['title' => 'Return assigned assets', 'category' => 'asset', 'owner_scope' => 'employee', 'owner' => $case->employee_id],
            ['title' => 'Submit pending documents', 'category' => 'document', 'owner_scope' => 'employee', 'owner' => $case->employee_id],
            ['title' => 'Settle leave encashment', 'category' => 'task', 'owner_scope' => 'hr', 'owner' => null],
            ['title' => 'Settle expense claims', 'category' => 'task', 'owner_scope' => 'employee', 'owner' => $case->employee_id],
            ['title' => 'Revoke system access', 'category' => 'access', 'owner_scope' => 'it', 'owner' => null],
        ];

        return DB::transaction(function () use ($case, $items, $actor): OffboardingCase {
            foreach (array_values($items) as $position => $item) {
                OffboardingCaseTask::create([
                    'case_id' => $case->id,
                    'title' => $item['title'],
                    'category' => $item['category'],
                    'owner_scope' => $item['owner_scope'],
                    'owner_employee_id' => $item['owner'],
                    'due_date' => $case->last_working_day,
                    'position' => ($position + 1) * 10,
                ]);
            }

            // The “submit pending documents” item is only honest if the
            // employee’s open asks point at this exit: re-point them, so the
            // clearance counts what HR actually asked for.
            DocumentRequest::query()
                ->where('employee_id', $case->employee_id)
                ->outstanding()
                ->whereNull('case_id')
                ->update(['case_type' => 'offboarding', 'case_id' => $case->id]);

            $this->audit->log($case, 'offboarding.checklist_built', null, [
                'employee_id' => $case->employee_id,
                'status' => $case->status->value,
            ], $actor);

            return $case->refresh();
        });
    }

    /**
     * Mark one checklist item done.
     *
     * @throws ValidationException on a closed item or case
     */
    public function completeTask(OffboardingCaseTask $task, ?User $actor = null, ?string $note = null): OffboardingCaseTask
    {
        if ($task->status->isResolved()) {
            throw ValidationException::withMessages(['form' => "This item is already {$task->status->value}."]);
        }

        if (! $task->case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => 'This case is closed.']);
        }

        return DB::transaction(function () use ($task, $actor, $note): OffboardingCaseTask {
            $task->update([
                'status' => CaseTaskStatus::Done->value,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'note' => $note,
            ]);
            $this->audit->log($task->case, 'offboarding.task_completed', null, [
                'case_id' => $task->case_id,
                'title' => $task->title,
                'status' => $task->status->value,
            ], $actor);

            return $task->refresh();
        });
    }
}
