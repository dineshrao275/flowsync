<?php

namespace App\Services\Hrms;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\Hrms\Lifecycle\OnboardingTemplateTask;
use App\Models\User;
use App\Services\Hrms\Lifecycle\OnboardingCases;
use App\Services\Hrms\Lifecycle\OnboardingTemplates;
use Illuminate\Support\Collection;

/**
 * Onboarding/HRMS — the reusable checklists and the cases they start.
 *
 * A thin orchestrator on purpose. Template catalogue rules live in
 * {@see OnboardingTemplates}; everything about running a case lives in
 * {@see OnboardingCases}; the document asks raised along the way live in the
 * shared request service it delegates to. A controller, a command or a future
 * queue worker therefore cannot reimplement one rule and drift from the
 * others.
 */
class OnboardingService
{
    public function __construct(
        private readonly OnboardingTemplates $templates,
        private readonly OnboardingCases $cases,
    ) {}

    /**
     * @return Collection<int, OnboardingTemplate>
     */
    public function templates(bool $activeOnly = true): Collection
    {
        return $this->templates->all($activeOnly);
    }

    /**
     * @param  array{description?: string|null, is_active?: bool, tasks?: list<array<string, mixed>>}  $attributes
     */
    public function createTemplate(string $name, array $attributes = [], ?User $actor = null): OnboardingTemplate
    {
        return $this->templates->create($name, $attributes, $actor);
    }

    /**
     * @param  array{description?: string|null, is_active?: bool}  $attributes
     */
    public function updateTemplate(OnboardingTemplate $template, string $name, array $attributes = [], ?User $actor = null): OnboardingTemplate
    {
        return $this->templates->update($template, $name, $attributes, $actor);
    }

    public function deleteTemplate(OnboardingTemplate $template, ?User $actor = null): void
    {
        $this->templates->delete($template, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addTemplateTask(OnboardingTemplate $template, array $attributes, ?User $actor = null): OnboardingTemplateTask
    {
        return $this->templates->addTask($template, $attributes, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTemplateTask(OnboardingTemplateTask $task, array $attributes, ?User $actor = null): OnboardingTemplateTask
    {
        return $this->templates->updateTask($task, $attributes, $actor);
    }

    public function removeTemplateTask(OnboardingTemplateTask $task, ?User $actor = null): void
    {
        $this->templates->removeTask($task, $actor);
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderTemplateTasks(OnboardingTemplate $template, array $orderedIds): void
    {
        $this->templates->reorderTasks($template, $orderedIds);
    }

    /**
     * Cases the viewer may list: everything for a directory reader, only
     * their own run for anyone else. The per-record policy re-checks on
     * show — a list that leaked another hire’s case would be the policy’s
     * failure, not this scope’s generosity.
     *
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, OnboardingCase>
     */
    public function casesFor(User $viewer, array $filters = []): Collection
    {
        $query = OnboardingCase::query()->with('employee')->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! $this->canViewAll($viewer)) {
            $employeeId = Employee::where('user_id', $viewer->id)->value('id');

            $query->where('employee_id', $employeeId ?? -1);
        }

        return $query->get();
    }

    private function canViewAll(User $viewer): bool
    {
        return $viewer->hasPermission('hrms.onboarding.view')
            || $viewer->hasPermission('hrms.onboarding.manage');
    }

    public function createCase(Employee $employee, OnboardingTemplate $template, ?User $actor = null): OnboardingCase
    {
        return $this->cases->createCase($employee, $template, $actor);
    }

    public function completeTask(OnboardingCaseTask $task, ?User $actor = null, ?string $note = null): OnboardingCaseTask
    {
        return $this->cases->completeTask($task, $actor, $note);
    }

    public function waiveTask(OnboardingCaseTask $task, string $reason, ?User $actor = null): OnboardingCaseTask
    {
        return $this->cases->waiveTask($task, $reason, $actor);
    }

    /**
     * @return array{total: int, done: int, waived: int, open: int, mandatory_total: int, mandatory_open: int, percent: int}
     */
    public function progress(OnboardingCase $case): array
    {
        return $this->cases->progress($case);
    }

    public function complete(OnboardingCase $case, ?User $actor = null): OnboardingCase
    {
        return $this->cases->complete($case, $actor);
    }

    public function cancel(OnboardingCase $case, ?User $actor = null): OnboardingCase
    {
        return $this->cases->cancel($case, $actor);
    }
}
