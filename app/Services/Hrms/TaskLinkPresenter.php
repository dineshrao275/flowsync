<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\TaskLinkKind;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Task;

/**
 * TaskLink/HRMS — what a bridge row looks like over HTTP.
 *
 * One shape for the task side and the employee side: the link carries its
 * kind label (pills render from it) and both endpoints name the same keys,
 * so the drawer panel and the profile tab cannot drift into two dialects
 * for the same row.
 */
class TaskLinkPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(TaskLink $link): array
    {
        $kind = $link->kind instanceof TaskLinkKind ? $link->kind : TaskLinkKind::from((string) $link->kind);

        return [
            'id' => $link->id,
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'note' => $link->note,
            'created_at' => $link->created_at?->toISOString(),
            'employee' => $link->employee === null ? null : [
                'id' => $link->employee->id,
                'employee_code' => $link->employee->employee_code,
                'name' => $link->employee->name,
            ],
            'task' => $link->task === null ? null : [
                'id' => $link->task->id,
                'key' => $link->task->key,
                'title' => $link->task->title,
            ],
            'creator' => $link->creator === null ? null : [
                'id' => $link->creator->id,
                'name' => $link->creator->name,
            ],
        ];
    }

    /**
     * The link half of an employee-tasks row: the task shape itself comes
     * from the canonical task presenter, this rides alongside as `link`.
     *
     * @return array<string, mixed>
     */
    public function presentLinkMeta(Task $task): array
    {
        $link = $task->getRelation('taskLink');

        if (! $link instanceof TaskLink) {
            return [];
        }

        $presented = $this->present($link);
        unset($presented['employee'], $presented['task']);

        return $presented;
    }
}
