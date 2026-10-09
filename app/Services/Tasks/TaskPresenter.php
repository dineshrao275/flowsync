<?php

namespace App\Services\Tasks;

use App\Models\Label;
use App\Models\ProjectComponent;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;

/**
 * Task and status wire shapes. Moved out of TaskService (P1.7).
 */
class TaskPresenter
{
    public function presentStatus(TaskStatus $status): array
    {
        return [
            'id' => $status->id,
            'name' => $status->name,
            'slug' => $status->slug,
            'category' => $status->category->value,
            'position' => $status->position,
            'color' => $status->color,
            'is_default' => $status->is_default,
            'is_done' => $status->is_done,
        ];
    }

    public function present(Task $task): array
    {
        return [
            'id' => $task->id,
            'key' => $task->key,
            'sequence' => $task->sequence,
            'title' => $task->title,
            'description' => $task->description,
            'parent_id' => $task->parent_id,
            'status_id' => $task->status_id,
            'status' => $task->status ? $this->presentStatus($task->status) : null,
            'priority_id' => $task->priority_id,
            'priority' => $task->priority ? [
                'id' => $task->priority->id,
                'name' => $task->priority->name,
                'slug' => $task->priority->slug,
                'color' => $task->priority->color,
                'value' => $task->priority->value,
            ] : null,
            'assignee_id' => $task->assignee_id,
            'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name, 'email' => $task->assignee->email] : null,
            'labels' => $task->labels->map(fn (Label $label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])->values(),
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'story_points' => $task->story_points !== null ? (float) $task->story_points : null,
            'sprint_id' => $task->sprint_id,
            'issue_type_id' => $task->issue_type_id,
            'issue_type' => $task->issueType ? [
                'id' => $task->issueType->id,
                'name' => $task->issueType->name,
                'slug' => $task->issueType->slug,
                'icon' => $task->issueType->icon,
                'color' => $task->issueType->color,
                'is_subtask' => $task->issueType->is_subtask,
            ] : null,
            'version_id' => $task->version_id,
            'version' => $task->version ? [
                'id' => $task->version->id,
                'name' => $task->version->name,
                'released' => $task->version->released,
                'release_date' => $task->version->release_date?->toDateString(),
            ] : null,
            'components' => $task->components->map(fn (ProjectComponent $comp) => [
                'id' => $comp->id,
                'name' => $comp->name,
            ])->values(),
            'watchers' => ($task->relationLoaded('watchers') ? $task->watchers : $task->watchers()->select(['users.id', 'users.name', 'users.email'])->get())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values(),
            'estimate_minutes' => $task->estimate_minutes,
            'position' => $task->position,
            'completed_at' => $task->completed_at?->toISOString(),
            'archived_at' => $task->archived_at?->toISOString(),
            'created_at' => $task->created_at?->toISOString(),
            'subtasks_count' => $task->subtasks_count ?? 0,
            'comments_count' => $task->comments_count ?? 0,
            'attachments_count' => $task->attachments_count ?? 0,
            'open_blockers_count' => $task->open_blockers_count ?? 0,
            'checklist_done_count' => (int) ($task->checklist_done_count ?? 0),
            'checklist_total' => (int) ($task->checklist_total ?? 0),
        ];
    }
}
