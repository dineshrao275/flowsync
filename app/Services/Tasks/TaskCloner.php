<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;

/**
 * Clone a task (P4.6) into its own or another project of the tenant. A clone is a fresh task:
 * it starts in the target's default status with a new key, keeps the content (title, text,
 * dates, estimate, points, priority, type, labels, checklist) and drops everything that is
 * history (comments, attachments, work logs, watchers, sprint, status history). Project-bound
 * references (components, version, epic, assignee) survive only inside the same project, or
 * for an assignee who is a member of the target.
 */
class TaskCloner
{
    public function __construct(private readonly TaskService $tasks, private readonly TaskChangeLogger $changes) {}

    /**
     * @param  array{title?: ?string, include_subtasks?: bool, include_checklist?: bool}  $options
     * @return array{task: Task, subtasks: int}
     */
    public function clone(Task $source, Project $target, array $options, User $actor, ?string $ip): array
    {
        return DB::transaction(function () use ($source, $target, $options, $actor, $ip) {
            $clone = $this->copy($source, $target, $actor, $options['title'] ?? 'Clone of '.$source->title, null);
            $this->changes->created($clone, $actor, $ip, ['cloned_from' => $source->key]);

            if (($options['include_checklist'] ?? true) === true) {
                $this->copyChecklist($source, $clone);
            }

            $count = 0;
            if (($options['include_subtasks'] ?? false) === true) {
                foreach ($source->subtasks()->orderBy('id')->get() as $sub) {
                    $child = $this->copy($sub, $target, $actor, $sub->title, $clone);
                    $this->changes->created($child, $actor, $ip, ['cloned_from' => $sub->key]);
                    $count++;
                }
            }

            return ['task' => $clone, 'subtasks' => $count];
        });
    }

    private function copy(Task $source, Project $target, User $actor, string $title, ?Task $parent): Task
    {
        $same = $source->project_id === $target->id;
        $source->loadMissing('issueType');
        $assignee = $source->assignee_id !== null ? User::find($source->assignee_id) : null;

        $data = [
            'title' => $title,
            'description' => $source->description,
            'priority_id' => $source->priority_id,
            'assignee_id' => $assignee !== null && $target->isMember($assignee) ? $assignee->id : null,
            'issue_type_id' => $source->issueType?->is_subtask && $parent === null ? null : $source->issue_type_id,
            'parent_id' => $parent?->id,
            'start_date' => $source->start_date?->toDateString(),
            'due_date' => $source->due_date?->toDateString(),
            'story_points' => $source->story_points,
            'estimate_minutes' => $source->estimate_minutes,
            'labels' => $source->workspace_id === $target->workspace_id ? $source->labels()->pluck('labels.id')->all() : [],
        ];

        if ($same) {
            $data['version_id'] = $source->version_id;
            $data['epic_id'] = $parent === null ? $source->epic_id : null;
            $data['components'] = $source->components()->pluck('project_components.id')->all();
        }

        return $this->tasks->create($target, $data, $actor);
    }

    private function copyChecklist(Task $source, Task $clone): void
    {
        foreach ($source->checklistItems()->get() as $item) {
            TaskChecklistItem::create(['task_id' => $clone->id, 'title' => $item->title, 'is_done' => false, 'position' => $item->position]);
        }
    }
}
