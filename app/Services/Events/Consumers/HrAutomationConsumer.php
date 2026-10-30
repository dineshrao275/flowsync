<?php

declare(strict_types=1);

namespace App\Services\Events\Consumers;

use App\Models\DomainEvent;
use App\Models\HrAutomationRule;
use App\Models\HrAutomationRun;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\ResignationRequest;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\TaskService;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * HR event → work. For each active rule listening to the event type, create the rule's tasks in its
 * project: titles may use {employee} and {date}; the assignee is the employee, their manager, or nobody.
 * Tasks are made through TaskService as the rule's author (else the project lead), so quotas, keys and
 * membership checks apply; an assignee who is not a project member is simply left unassigned.
 * One run per (rule, event) — a redelivered event never doubles the tasks.
 */
class HrAutomationConsumer
{
    public function __construct(private readonly TaskService $tasks, private readonly ReportingLine $reporting) {}

    public function handle(DomainEvent $event): void
    {
        if (! Schema::hasTable('hr_automation_rules') || ! HrmsSchema::present()) {
            return;
        }

        $employee = $this->employeeFor($event);

        foreach (HrAutomationRule::query()->where('trigger', $event->type)->where('is_active', true)->with('project')->get() as $rule) {
            $run = HrAutomationRun::firstOrCreate(
                ['rule_id' => $rule->id, 'event_uuid' => $event->uuid],
                ['employee_id' => $employee?->id, 'status' => 'skipped'],
            );

            if (! $run->wasRecentlyCreated) {
                continue;
            }

            if ($employee === null || $rule->project === null) {
                $run->update(['summary' => 'No employee or project to act on.']);

                continue;
            }

            try {
                $made = $this->createTasks($rule, $employee);
                $run->update(['status' => 'success', 'summary' => "Created {$made} task(s)."]);
                $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->save();
            } catch (Throwable $e) {
                $run->update(['status' => 'failed', 'summary' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
    }

    private function createTasks(HrAutomationRule $rule, Employee $employee): int
    {
        $project = $rule->project;
        $actor = ($rule->created_by ? User::find($rule->created_by) : null) ?? ($project->lead_user_id ? User::find($project->lead_user_id) : null);

        if ($actor === null) {
            throw new \RuntimeException('The rule has no author or project lead to act as.');
        }

        $memberIds = array_map('intval', $project->members()->pluck('users.id')->all());
        $managerUserId = $this->reporting->managerOf($employee)?->user_id;
        $made = 0;

        foreach ($rule->tasks as $spec) {
            $assignee = match ($spec['assignee'] ?? 'none') {
                'employee' => $employee->user_id,
                'manager' => $managerUserId,
                default => null,
            };

            $this->tasks->create($project, array_filter([
                'title' => str_replace(['{employee}', '{date}'], [$employee->name, now()->toDateString()], (string) $spec['title']),
                'description' => $spec['description'] ?? null,
                'assignee_id' => $assignee !== null && in_array((int) $assignee, $memberIds, true) ? (int) $assignee : null,
                'due_date' => isset($spec['due_in_days']) ? now()->addDays((int) $spec['due_in_days'])->toDateString() : null,
            ], fn ($v) => $v !== null), $actor);
            $made++;
        }

        return $made;
    }

    /** The employee the event is about, resolved from the event's subject. */
    private function employeeFor(DomainEvent $event): ?Employee
    {
        $id = (int) ($event->data['employee_id'] ?? 0);

        if ($id === 0 && $event->subject_id) {
            $id = match ($event->subject_type) {
                (new Employee)->getMorphClass() => (int) $event->subject_id,
                (new LeaveRequest)->getMorphClass() => (int) LeaveRequest::query()->whereKey($event->subject_id)->value('employee_id'),
                (new OnboardingCase)->getMorphClass() => (int) OnboardingCase::query()->whereKey($event->subject_id)->value('employee_id'),
                (new OffboardingCase)->getMorphClass() => (int) OffboardingCase::query()->whereKey($event->subject_id)->value('employee_id'),
                (new ResignationRequest)->getMorphClass() => (int) ResignationRequest::query()->whereKey($event->subject_id)->value('employee_id'),
                default => 0,
            };
        }

        return $id ? Employee::query()->find($id) : null;
    }
}
