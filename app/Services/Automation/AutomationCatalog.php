<?php

namespace App\Services\Automation;

use Illuminate\Validation\ValidationException;

/**
 * What a rule can be made of. The same catalog drives the validation of a saved rule and
 * the builder UI, so a trigger/condition/action can never exist in one and not the other.
 */
final class AutomationCatalog
{
    public const TRIGGERS = [
        'task.created' => 'A task is created',
        'task.updated' => 'A task is edited',
        'task.moved' => 'A task changes status',
        'task.completed' => 'A task is completed',
        'task.assigned' => 'A task is assigned',
        'task.commented' => 'A comment is added',
        'task.overdue' => 'A task becomes overdue',
        'task.due_soon' => 'A task is due within 24 hours',
    ];

    /** field => [label, ops] */
    public const FIELDS = [
        'status' => ['Status', ['is', 'is_not', 'in']],
        'status_category' => ['Status category (todo / in_progress / done)', ['is', 'is_not']],
        'priority' => ['Priority', ['is', 'is_not', 'in']],
        'assignee' => ['Assignee', ['is', 'is_not', 'is_empty', 'is_not_empty']],
        'reporter' => ['Reporter', ['is', 'is_not']],
        'issue_type' => ['Issue type', ['is', 'is_not']],
        'label' => ['Label', ['has', 'has_not']],
        'title' => ['Title', ['contains', 'not_contains']],
        'due_date' => ['Due date', ['is_empty', 'is_not_empty', 'is_overdue']],
        'story_points' => ['Story points', ['gt', 'lt', 'eq']],
    ];

    /** type => label */
    public const ACTIONS = [
        'set_assignee' => 'Assign to someone',
        'set_priority' => 'Set the priority',
        'move_to_status' => 'Move to a status',
        'add_label' => 'Add a label',
        'add_comment' => 'Add a comment',
        'notify' => 'Send a notification',
    ];

    public const MAX_CONDITIONS = 10;

    public const MAX_ACTIONS = 5;

    /** @return array<string, mixed> for the builder UI */
    public static function describe(): array
    {
        return [
            'triggers' => self::TRIGGERS,
            'fields' => collect(self::FIELDS)->map(fn ($f) => ['label' => $f[0], 'ops' => $f[1]])->all(),
            'actions' => self::ACTIONS,
            'placeholders' => ['{{key}}', '{{title}}', '{{assignee}}', '{{status}}', '{{priority}}'],
        ];
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @param  array<int, mixed>  $actions
     *
     * @throws ValidationException
     */
    public static function assertValid(string $trigger, array $conditions, array $actions): void
    {
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if (! isset(self::TRIGGERS[$trigger])) {
            $fail('trigger', 'Unknown trigger.');
        }
        if (count($conditions) > self::MAX_CONDITIONS) {
            $fail('conditions', 'At most '.self::MAX_CONDITIONS.' conditions.');
        }
        if ($actions === [] || count($actions) > self::MAX_ACTIONS) {
            $fail('actions', 'A rule needs between 1 and '.self::MAX_ACTIONS.' actions.');
        }

        foreach ($conditions as $i => $c) {
            $field = $c['field'] ?? null;
            $op = $c['op'] ?? null;
            if (! isset(self::FIELDS[$field]) || ! in_array($op, self::FIELDS[$field][1], true)) {
                $fail("conditions.{$i}", 'Unknown condition.');
            }
            $needsValue = ! in_array($op, ['is_empty', 'is_not_empty', 'is_overdue'], true);
            if ($needsValue && (! array_key_exists('value', $c) || $c['value'] === '' || $c['value'] === null || $c['value'] === [])) {
                $fail("conditions.{$i}", 'This condition needs a value.');
            }
        }

        foreach ($actions as $i => $a) {
            $type = $a['type'] ?? null;
            if (! isset(self::ACTIONS[$type])) {
                $fail("actions.{$i}", 'Unknown action.');
            }
            $required = ['set_assignee' => 'user', 'set_priority' => 'priority', 'move_to_status' => 'status', 'add_label' => 'label', 'add_comment' => 'text', 'notify' => 'message'][$type];
            if (blank($a[$required] ?? null)) {
                $fail("actions.{$i}", "This action needs '{$required}'.");
            }
        }
    }
}
