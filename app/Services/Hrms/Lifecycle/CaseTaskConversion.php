<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\TaskLinkKind;
use App\Enums\Hrms\TemplateTaskCategory;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Hrms\TaskLinkService;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — turning checklist items into project tasks.
 *
 * A `task`-category item converts once: the item's title, description and
 * due date become a project task (assigned to the owner's login when that
 * login sits on the project, unassigned otherwise — assigning a stranger
 * would 422 inside `TaskService`, so the membership is checked first),
 * and a kind-`onboarding` link records what the task was raised from.
 *
 * Status flows one way and is pulled, never pushed: `syncFromTask` reads
 * the linked task's current completion and closes the item through the
 * owning service when the task is done. Nothing here ever writes to the
 * task — a manual reopen stays reopened, and a sync over an open task is
 * a no-op — so a stale checklist state can never overwrite a live edit.
 */
class CaseTaskConversion
{
    public const SOURCE_ONBOARDING = 'onboarding_task';

    public const SOURCE_OFFBOARDING = 'offboarding_task';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskLinkService $links,
        private readonly OnboardingCases $onboarding,
        private readonly OffboardingChecklist $offboarding,
    ) {}

    /**
     * @return array{task: Task, link: TaskLink}
     */
    public function convert(OnboardingCaseTask|OffboardingCaseTask $item, Project $project, User $actor, ?string $ipAddress = null): array
    {
        $this->requireConvertible($item);

        $employee = $this->ownerOf($item);
        $assigneeId = $this->projectLogin($project, $employee);

        $task = $this->tasks->create($project, array_filter([
            'title' => $item->title,
            'description' => $item->description,
            'due_date' => $item->due_date?->toDateString(),
            'assignee_id' => $assigneeId,
        ], fn ($value) => $value !== null), $actor);

        $link = $this->links->link(
            $employee,
            $task,
            TaskLinkKind::Onboarding,
            "Converted from checklist item: {$item->title}",
            $actor,
            $ipAddress,
            $this->sourceType($item),
            $item->id,
        );

        return ['task' => $task, 'link' => $link];
    }

    /**
     * Pull the linked task's completion into the item. Returns whether the
     * item transitioned: an open task, an already-resolved item, or a
     * reopened task all answer false — sync moves toward done only.
     */
    public function syncFromTask(OnboardingCaseTask|OffboardingCaseTask $item, ?User $actor = null): bool
    {
        if ($item->status->isResolved()) {
            return false;
        }

        $task = $this->linkedTask($item);

        if ($task === null) {
            throw ValidationException::withMessages(['form' => 'This item was never converted to a task.']);
        }

        if ($task->completed_at === null) {
            return false;
        }

        if ($item instanceof OnboardingCaseTask) {
            $this->onboarding->completeTask($item, $actor, 'Completed via the linked project task.');
        } else {
            $this->offboarding->completeTask($item, $actor, 'Completed via the linked project task.');
        }

        return true;
    }

    public function linkedTask(OnboardingCaseTask|OffboardingCaseTask $item): ?Task
    {
        $link = TaskLink::query()
            ->where('source_type', $this->sourceType($item))
            ->where('source_id', $item->id)
            ->orderBy('id')
            ->first();

        return $link === null ? null : Task::find($link->task_id);
    }

    public function isConverted(OnboardingCaseTask|OffboardingCaseTask $item): bool
    {
        return TaskLink::query()
            ->where('source_type', $this->sourceType($item))
            ->where('source_id', $item->id)
            ->exists();
    }

    private function requireConvertible(OnboardingCaseTask|OffboardingCaseTask $item): void
    {
        if ($item->category !== TemplateTaskCategory::Task->value) {
            throw ValidationException::withMessages(['form' => 'Only task-category items convert to project tasks.']);
        }

        if (! $item->case->status->isOpen()) {
            throw ValidationException::withMessages(['form' => 'This case is closed.']);
        }

        if ($this->isConverted($item)) {
            throw ValidationException::withMessages(['form' => 'This item already has a project task.']);
        }
    }

    private function ownerOf(OnboardingCaseTask|OffboardingCaseTask $item): Employee
    {
        $employee = Employee::find($item->owner_employee_id ?? $item->case->employee_id);

        if ($employee === null) {
            throw ValidationException::withMessages(['form' => 'This item names no employee to link the task to.']);
        }

        return $employee;
    }

    /**
     * The owner's login, but only when it sits on the project — handing
     * `TaskService` a stranger's id 422s inside the resolver, so the check
     * lives here where the fallback (unassigned) is expressible.
     */
    private function projectLogin(Project $project, Employee $employee): ?int
    {
        if ($employee->user_id === null) {
            return null;
        }

        $member = $project->members()->where('user_id', $employee->user_id)->exists();

        return $member ? (int) $employee->user_id : null;
    }

    private function sourceType(OnboardingCaseTask|OffboardingCaseTask $item): string
    {
        return $item instanceof OnboardingCaseTask ? self::SOURCE_ONBOARDING : self::SOURCE_OFFBOARDING;
    }
}
