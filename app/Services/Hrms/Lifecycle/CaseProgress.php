<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\CaseTaskStatus;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;

/**
 * Lifecycle/HRMS — where an onboarding case stands.
 *
 * Split from {@see OnboardingCases} because “what counts” is a
 * self-contained read model: the mandatory definition and the progress
 * photograph. Both the waive gate and the complete gate read the same
 * definition from here, so “mandatory” cannot mean one thing when waiving
 * and another when closing.
 */
class CaseProgress
{
    /**
     * Whether the template calls this item mandatory.
     *
     * Derived from the template link, not stored on the row: ad-hoc items
     * added mid-case are never mandatory, and a template task deleted while
     * its cases are still open keeps its items mandatory — a deleted
     * catalogue row must not quietly unblock a case. (Deleting a template
     * task referenced by an open case is refused outright; see
     * OnboardingService::removeTemplateTask.)
     */
    public function isMandatory(OnboardingCaseTask $task): bool
    {
        return $task->template_task_id !== null
            && (bool) $task->templateTask?->is_mandatory;
    }

    /**
     * Where this case stands. Waived counts as resolved but is reported
     * separately from done: “complete” and “complete after three waivers”
     * are different stories about the same hire.
     *
     * @return array{total: int, done: int, waived: int, open: int, mandatory_total: int, mandatory_open: int, percent: int}
     */
    public function for(OnboardingCase $case): array
    {
        $tasks = $case->tasks()->get();
        $resolved = $tasks->filter(fn (OnboardingCaseTask $task): bool => $task->status->isResolved());

        return [
            'total' => $tasks->count(),
            'done' => $tasks->where('status', CaseTaskStatus::Done)->count(),
            'waived' => $tasks->where('status', CaseTaskStatus::Waived)->count(),
            'open' => $tasks->count() - $resolved->count(),
            'mandatory_total' => $tasks->filter(fn (OnboardingCaseTask $task): bool => $this->isMandatory($task))->count(),
            'mandatory_open' => $tasks->filter(fn (OnboardingCaseTask $task): bool => $this->isMandatory($task) && ! $task->status->isResolved())->count(),
            'percent' => $tasks->isEmpty() ? 100 : (int) round($resolved->count() / $tasks->count() * 100),
        ];
    }

    /**
     * The titles blocking completion, for the 422 that refuses it.
     *
     * @return list<string>
     */
    public function blockingTitles(OnboardingCase $case): array
    {
        return $case->tasks()->get()
            ->filter(fn (OnboardingCaseTask $task): bool => $this->isMandatory($task) && ! $task->status->isResolved())
            ->map(fn (OnboardingCaseTask $task): string => $task->title)
            ->values()
            ->all();
    }
}
