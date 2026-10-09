<?php

namespace App\Services\Workflow;

use App\Models\StatusTransition;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Validation\ValidationException;

/**
 * The project's workflow rules for a status change:
 *  1. when the project enforces its workflow, the move must be on the allow-list;
 *  2. the target status's entry requirements must hold for the task as it will be saved.
 * Open-blocker handling stays in TaskService; this class owns only the configurable rules.
 */
class WorkflowGuard
{
    public const ENTRY_RULES = [
        'assignee' => 'an assignee',
        'due_date' => 'a due date',
        'estimate' => 'a time estimate',
        'story_points' => 'story points',
        'subtasks_done' => 'all subtasks to be done',
    ];

    /**
     * @param  array<string, mixed>  $pending  field values about to be saved together with the move
     *
     * @throws ValidationException
     */
    public function assertCanMove(Task $task, TaskStatus $to, array $pending = []): void
    {
        $reason = $this->denial($task, $to, $pending);

        if ($reason !== null) {
            throw ValidationException::withMessages(['form' => $reason]);
        }
    }

    /** @param array<string, mixed> $pending */
    public function denial(Task $task, TaskStatus $to, array $pending = []): ?string
    {
        if ((int) $task->status_id === (int) $to->id) {
            return null; // not a move
        }

        $project = $task->project;
        if ($project->enforce_workflow && ! $this->allowed((int) $task->status_id, $to->id, $project->id)) {
            $from = $task->status?->name ?? 'this status';

            return "This project's workflow does not allow moving a task from {$from} to {$to->name}.";
        }

        foreach ($to->entry_rules ?? [] as $rule) {
            if (! $this->satisfied($rule, $task, $pending)) {
                return "Moving to {$to->name} requires ".(self::ENTRY_RULES[$rule] ?? $rule).'.';
            }
        }

        return null;
    }

    /** @return list<int> ids of statuses the task may move to right now (ignores entry rules). */
    public function allowedTargets(Task $task): array
    {
        $statuses = $task->project->statuses()->pluck('id')->map(fn ($i) => (int) $i)->all();

        if (! $task->project->enforce_workflow) {
            return $statuses;
        }

        return array_values(array_filter($statuses, fn (int $id) => $id !== (int) $task->status_id && $this->allowed((int) $task->status_id, $id, $task->project_id)));
    }

    private function allowed(int $from, int $to, int $projectId): bool
    {
        return StatusTransition::where('project_id', $projectId)->where('to_status_id', $to)
            ->where(fn ($q) => $q->whereNull('from_status_id')->orWhere('from_status_id', $from))->exists();
    }

    /** @param array<string, mixed> $pending */
    private function satisfied(string $rule, Task $task, array $pending): bool
    {
        $value = fn (string $field) => array_key_exists($field, $pending) ? $pending[$field] : $task->{$field};

        return match ($rule) {
            'assignee' => $value('assignee_id') !== null,
            'due_date' => $value('due_date') !== null,
            'estimate' => $value('estimate_minutes') !== null,
            'story_points' => $value('story_points') !== null,
            'subtasks_done' => ! $task->subtasks()->whereNull('completed_at')->exists(),
            default => true,
        };
    }
}
