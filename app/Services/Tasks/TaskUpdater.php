<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\Models\Task;
use App\Services\Projects\ProjectIssueScheme;
use App\Services\Workflow\WorkflowGuard;
use Illuminate\Validation\ValidationException;

/**
 * The task update flow: resolves the edited fields, guards the move, saves,
 * and records completion side-effects. Extracted from TaskService to hold
 * the 300-line class ceiling; TaskService::update() delegates here.
 */
class TaskUpdater
{
    public function __construct(
        private readonly TaskInputResolver $inputs,
        private readonly TaskHierarchy $hierarchy,
        private readonly TaskLifecycle $lifecycle,
        private readonly WorkflowGuard $workflow,
    ) {}

    public function update(Task $task, array $data): Task
    {
        $status = $this->inputs->status($task->project, $data['status_id'] ?? $task->status_id);
        $priority = $this->inputs->priority($task->project, $data['priority_id'] ?? $task->priority_id);
        $assignee = $this->inputs->assignee($task->project, array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $task->assignee_id);
        $parent = $this->inputs->parent($task->project, array_key_exists('parent_id', $data) ? $data['parent_id'] : $task->parent_id);

        $statusChanged = ($data['status_id'] ?? null) !== null && (int) $data['status_id'] !== $task->status_id;

        if ($statusChanged && $status->is_done && $this->lifecycle->hasOpenBlockers($task)) {
            throw ValidationException::withMessages([
                'form' => __('Cannot complete a task that has open blockers.'),
            ]);
        }

        $updateData = [
            'title' => $data['title'] ?? $task->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $task->description,
            'status_id' => $status->id,
            'priority_id' => $priority?->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $task->due_date,
            'estimate_minutes' => array_key_exists('estimate_minutes', $data) ? $data['estimate_minutes'] : $task->estimate_minutes,
            'completed_at' => $status->is_done ? ($task->completed_at ?? now()) : null,
        ];

        if (array_key_exists('start_date', $data)) {
            $updateData['start_date'] = $data['start_date'];
        }
        if (array_key_exists('story_points', $data)) {
            $updateData['story_points'] = $data['story_points'];
        }
        $this->applyHierarchy($task, $data, $parent, $updateData);
        if (array_key_exists('version_id', $data)) {
            $updateData['version_id'] = $this->inputs->version($task->project, $data['version_id'])?->id;
        }

        $completing = $status->is_done && (int) $task->status_id !== (int) $status->id;
        $fromStatusId = (int) $task->status_id;
        $statusMoves = $fromStatusId !== (int) $status->id;
        if ($statusMoves) {
            $this->lifecycle->assertWip($status);
            // Judge the task as it is about to be saved, so one edit can satisfy the entry rule it triggers.
            $this->workflow->assertCanMove($task, $status, array_intersect_key($updateData, array_flip(['assignee_id', 'due_date', 'estimate_minutes', 'story_points'])));
        }

        $task->update($updateData);

        if (array_key_exists('labels', $data)) {
            $task->labels()->sync($this->inputs->labels($task->project, $data['labels'] ?? []));
        }

        if (array_key_exists('components', $data)) {
            $task->components()->sync($this->inputs->components($task->project, $data['components'] ?? []));
        }

        $updated = $task->fresh();

        if ($statusMoves) {
            $this->lifecycle->recordStatusChange($updated, $fromStatusId, (int) $status->id);
        }

        if ($completing) {
            $this->lifecycle->refreshGoalsOnCompletion($updated);
        }

        return $updated;
    }

    /** Validates the type/parent/epic a write ends with and adds the type and epic columns to the update. */
    private function applyHierarchy(Task $task, array $data, ?Task $parent, array &$updateData): void
    {
        $type = array_key_exists('issue_type_id', $data) ? $this->inputs->issueType($data['issue_type_id']) : $task->issueType;
        if (! empty($data['issue_type_id'])) {
            ProjectIssueScheme::assertAllowed($task->project, $type);
        }
        $epic = array_key_exists('epic_id', $data) ? $this->hierarchy->epic($task->project, $data['epic_id']) : $task->epic;

        $touched = array_key_exists('issue_type_id', $data) || array_key_exists('epic_id', $data) || array_key_exists('parent_id', $data);
        if ($touched) {
            if (array_key_exists('issue_type_id', $data) && $type !== null) {
                $this->hierarchy->assertRetypable($task, $type);
            }
            $this->hierarchy->assert($task, $type, $parent, $epic);
        }

        if (array_key_exists('issue_type_id', $data)) {
            $updateData['issue_type_id'] = $type?->id;
        }
        if (array_key_exists('epic_id', $data)) {
            $updateData['epic_id'] = $epic?->id;
        }
    }
}
