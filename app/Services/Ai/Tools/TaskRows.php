<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Task;

/** Turns task rows into the minimum the model and the UI need. No emails, roles or free-text fields beyond the title. */
final class TaskRows
{
    /** @return array<string,mixed> */
    public static function row(Task $task): array
    {
        $open = $task->completed_at === null;

        return [
            'key' => $task->key,
            'title' => $task->title,
            'status' => $task->status?->name,
            'priority' => $task->priority?->name,
            'assignee' => $task->assignee?->name,
            'due_date' => $task->due_date?->toDateString(),
            'overdue' => $open && $task->due_date !== null && $task->due_date->lt(today()),
            'project' => $task->project?->name,
        ];
    }

    /** @return array<string,mixed> */
    public static function card(Task $task): array
    {
        $row = self::row($task);

        return [
            'type' => 'task',
            'title' => "{$row['key']} · {$row['title']}",
            'subtitle' => implode(' · ', array_filter([$row['project'], $row['status'], $row['assignee']])),
            'badges' => array_values(array_filter([$row['priority'], $row['due_date'] ? 'due '.$row['due_date'] : null, $row['overdue'] ? 'overdue' : null])),
            'link' => AiLinks::task((int) $task->project_id, (string) $task->key),
        ];
    }
}
