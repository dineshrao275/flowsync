<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\DomainEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs the rules a domain event triggers. Guards: a rule runs at most once per event
 * (unique run row), never on an event it caused itself, and nothing runs once a chain of
 * automations is three deep.
 */
class AutomationEngine
{
    public const MAX_DEPTH = 3;

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ActionRunner $actions,
    ) {}

    public function handle(DomainEvent $event): void
    {
        if ($event->subject_type !== Task::class || ! $event->subject_id || ! isset(AutomationCatalog::TRIGGERS[$event->type])) {
            return;
        }

        $depth = (int) ($event->data['automation_depth'] ?? 0);
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $task = Task::with('project')->find($event->subject_id);
        if (! $task) {
            return;
        }

        $rules = AutomationRule::where('project_id', $task->project_id)->where('trigger', $event->type)->where('is_active', true)->get();

        foreach ($rules as $rule) {
            if ((int) ($event->data['automation_rule_id'] ?? 0) === $rule->id) {
                continue;
            }
            $this->runRule($rule, $event, $task, $depth);
        }
    }

    private function runRule(AutomationRule $rule, DomainEvent $event, Task $task, int $depth): void
    {
        $run = AutomationRun::firstOrCreate(
            ['rule_id' => $rule->id, 'event_uuid' => $event->uuid],
            ['task_id' => $task->id, 'status' => 'skipped'],
        );
        if (! $run->wasRecentlyCreated) {
            return; // this event already ran this rule
        }

        $task = $task->fresh(['project']) ?? $task;

        if (! $this->conditions->passes($task, $rule->conditions ?? [])) {
            $run->update(['summary' => 'Conditions not met.']);

            return;
        }

        $actor = ($rule->created_by ? User::find($rule->created_by) : null)
            ?? ($task->project->lead_user_id ? User::find($task->project->lead_user_id) : null);
        if (! $actor) {
            $run->update(['status' => 'failed', 'summary' => 'The rule has no author or project lead to act as.']);

            return;
        }

        $done = [];
        try {
            foreach ($rule->actions as $action) {
                $done[] = $this->actions->run($action, $task->fresh(['project', 'status', 'priority', 'labels']) ?? $task, $actor, $rule->id, $depth);
            }
            $run->update(['status' => 'success', 'summary' => mb_substr(implode('; ', $done), 0, 500)]);
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $run->update(['status' => 'failed', 'summary' => mb_substr(trim(implode('; ', $done).' — stopped: '.$message, '; —'), 0, 500)]);
        }

        $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->save();
    }
}
