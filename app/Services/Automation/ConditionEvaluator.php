<?php

namespace App\Services\Automation;

use App\Models\Task;

/** Answers whether a task satisfies a rule's conditions (all of them — AND). */
class ConditionEvaluator
{
    /** @param array<int, array{field: string, op: string, value?: mixed}> $conditions */
    public function passes(Task $task, array $conditions): bool
    {
        $task->loadMissing(['status', 'priority', 'labels', 'issueType']);

        foreach ($conditions as $c) {
            if (! $this->check($task, $c['field'], $c['op'], $c['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function check(Task $task, string $field, string $op, mixed $value): bool
    {
        return match ($field) {
            'status' => $this->compare($task->status?->slug, $op, $value),
            'status_category' => $this->compare($task->status?->category instanceof \BackedEnum ? $task->status->category->value : $task->status?->category, $op, $value),
            'priority' => $this->compare($task->priority?->slug, $op, $value),
            'assignee' => match ($op) {
                'is_empty' => $task->assignee_id === null,
                'is_not_empty' => $task->assignee_id !== null,
                default => $this->compare($task->assignee_id, $op, $value),
            },
            'reporter' => $this->compare($task->reporter_user_id, $op, $value),
            'issue_type' => $this->compare($task->issueType?->slug, $op, $value),
            'label' => in_array((int) $value, $task->labels->pluck('id')->map(fn ($i) => (int) $i)->all(), true) === ($op === 'has'),
            'title' => str_contains(mb_strtolower((string) $task->title), mb_strtolower((string) $value)) === ($op === 'contains'),
            'due_date' => match ($op) {
                'is_empty' => $task->due_date === null,
                'is_not_empty' => $task->due_date !== null,
                'is_overdue' => $task->due_date !== null && $task->due_date->startOfDay()->lt(now()->startOfDay()) && $task->completed_at === null,
                default => false,
            },
            'story_points' => $task->story_points !== null && match ($op) {
                'gt' => (float) $task->story_points > (float) $value,
                'lt' => (float) $task->story_points < (float) $value,
                'eq' => (float) $task->story_points === (float) $value,
                default => false,
            },
            default => false,
        };
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        $norm = fn ($v) => is_numeric($v) ? (string) (int) $v : (string) $v;
        $actual = $actual === null ? null : $norm($actual);

        return match ($op) {
            'is' => $actual === $norm($expected),
            'is_not' => $actual !== $norm($expected),
            'in' => $actual !== null && in_array($actual, array_map($norm, (array) $expected), true),
            default => false,
        };
    }
}
