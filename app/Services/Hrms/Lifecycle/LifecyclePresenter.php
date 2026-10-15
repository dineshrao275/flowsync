<?php

namespace App\Services\Hrms\Lifecycle;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\ExitClearance;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\Hrms\Lifecycle\OnboardingTemplateTask;
use Illuminate\Support\Collection;

/**
 * Lifecycle/HRMS — every lifecycle payload, shaped in one place.
 *
 * Separate from the services so “what a case looks like” cannot drift
 * between the template editor, the case detail, and the clearance panel:
 * each of those would otherwise grow its own keys for the same rows, and a
 * client reading three shapes for one checklist is a client that breaks on
 * the fourth. No endpoint returns `$model->toArray()`, and no controller
 * invents key names.
 */
class LifecyclePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function template(OnboardingTemplate $template): array
    {
        $template->loadMissing('tasks');

        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'is_active' => $template->is_active,
            'is_system' => $template->is_system,
            'tasks' => $template->tasks->map(fn (OnboardingTemplateTask $task): array => $this->templateTask($task))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function templateTask(OnboardingTemplateTask $task): array
    {
        return [
            'id' => $task->id,
            'template_id' => $task->template_id,
            'title' => $task->title,
            'description' => $task->description,
            'category' => $task->category->value,
            'category_label' => $task->category->label(),
            'owner_scope' => $task->owner_scope->value,
            'owner_scope_label' => $task->owner_scope->label(),
            'due_offset_days' => $task->due_offset_days,
            'is_mandatory' => $task->is_mandatory,
            'position' => $task->position,
        ];
    }

    /**
     * @param  array{total: int, done: int, waived: int, open: int, mandatory_total: int, mandatory_open: int, percent: int}  $progress
     * @param  Collection<int, DocumentRequest>  $requests
     * @return array<string, mixed>
     */
    public function onboardingCase(OnboardingCase $case, array $progress, Collection $requests): array
    {
        $case->loadMissing(['employee', 'template']);

        return [
            'id' => $case->id,
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'started_at' => $case->started_at?->toDateTimeString(),
            'completed_at' => $case->completed_at?->toDateTimeString(),
            'employee' => $this->person($case->employee),
            'template' => $case->template ? [
                'id' => $case->template->id,
                'name' => $case->template->name,
            ] : null,
            'tasks' => $case->tasks()->with('owner')->orderBy('position')->orderBy('id')->get()
                ->map(fn (OnboardingCaseTask $task): array => $this->caseTask($task))->all(),
            'progress' => $progress,
            'requests' => $requests->map(fn (DocumentRequest $request): array => $this->request($request))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function caseTask(OnboardingCaseTask|OffboardingCaseTask $task): array
    {
        return [
            'id' => $task->id,
            'case_id' => $task->case_id,
            'title' => $task->title,
            'description' => $task->description,
            'category' => $task->category,
            'owner_scope' => $task->owner_scope,
            'owner' => $task->owner ? $this->person($task->owner) : null,
            'due_date' => $task->due_date?->toDateString(),
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'note' => $task->note,
            'position' => $task->position,
        ];
    }

    /**
     * @param  Collection<int, DocumentRequest>  $requests
     * @return array<string, mixed>
     */
    public function offboardingCase(OffboardingCase $case, ExitClearance $clearance, Collection $requests): array
    {
        $case->loadMissing('employee');

        return [
            'id' => $case->id,
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'reason' => $case->reason->value,
            'reason_label' => $case->reason->label(),
            'last_working_day' => $case->last_working_day->toDateString(),
            'notice_period_days' => $case->notice_period_days,
            'exit_interview_at' => $case->exit_interview_at?->toDateTimeString(),
            'resignation_received_at' => $case->resignation_received_at?->toDateTimeString(),
            'employee' => $this->person($case->employee),
            'tasks' => $case->tasks()->with('owner')->orderBy('position')->orderBy('id')->get()
                ->map(fn (OffboardingCaseTask $task): array => $this->caseTask($task))->all(),
            'clearance' => $this->clearance($clearance),
            'requests' => $requests->map(fn (DocumentRequest $request): array => $this->request($request))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearance(ExitClearance $clearance): array
    {
        return [
            'pending_assets_count' => $clearance->pending_assets_count,
            'pending_leave_encashment_days' => $clearance->pending_leave_encashment_days,
            'pending_expense_amount' => $clearance->pending_expense_amount,
            'pending_documents_count' => $clearance->pending_documents_count,
            'dues_settled' => $clearance->dues_settled,
            'blocked' => $clearance->isBlocked(),
            'blocked_reasons' => $clearance->blocked_reasons ?? [],
            'cleared_at' => $clearance->cleared_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(DocumentRequest $request): array
    {
        $request->loadMissing(['type', 'document']);

        return [
            'id' => $request->id,
            'employee_id' => $request->employee_id,
            'title' => $request->title,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'source' => $request->source->value,
            'due_date' => $request->due_date?->toDateString(),
            'note' => $request->note,
            'type' => $request->type ? [
                'id' => $request->type->id,
                'name' => $request->type->name,
                'category' => $request->type->category->value,
            ] : null,
            'document' => $request->document ? [
                'id' => $request->document->id,
                'title' => $request->document->title,
                'original_name' => $request->document->original_name,
            ] : null,
        ];
    }

    /**
     * The person a row belongs to. `user_id` travels along so the client can
     * answer “is this me” without a second request — the same linkage the
     * self-service policies already enforce server-side.
     *
     * @return array<string, mixed>
     */
    private function person(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'name' => $employee->displayName(),
            'employee_code' => $employee->employee_code,
        ];
    }
}
