<?php

namespace App\Services\Tasks;

use App\Models\IssueType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

/**
 * Issue hierarchy rules (P4.1): Initiative(0) -> Epic(1) -> Story/Task/Bug(2) -> Sub-task(3).
 *
 * Two links carry the tree. `parent_id` is the sub-task link (a sub-task sits under a level-2
 * issue; the legacy "task under task" shape stays valid). `epic_id` is the epic link (a
 * level-2 issue links to an epic, an epic to an initiative); it is separate from `parent_id`
 * so the board, which shows only top-level rows, keeps listing an epic's stories. Every link
 * points strictly up the levels, so a cycle cannot form.
 */
class TaskHierarchy
{
    public const LEVEL_NAMES = [0 => 'initiative', 1 => 'epic', 2 => 'standard', 3 => 'sub-task'];

    public function levelOf(?Task $task): int
    {
        return $task?->issueType?->level() ?? 2;
    }

    /** Resolves the epic link for a write: same project, one level above the child. */
    public function epic(Project $project, $epicId): ?Task
    {
        if ($epicId === null || $epicId === '') {
            return null;
        }

        $epic = $project->tasks()->with('issueType')->find($epicId);

        if ($epic === null) {
            throw ValidationException::withMessages(['epic_id' => 'The selected epic does not belong to this project.']);
        }

        return $epic;
    }

    /** Throws 422 (field-keyed) when the type/parent/epic combination breaks the hierarchy. */
    public function assert(?Task $task, ?IssueType $type, ?Task $parent, ?Task $epic): void
    {
        $level = $type?->level() ?? 2;

        if ($task !== null && ($parent?->id === $task->id || $epic?->id === $task->id)) {
            throw ValidationException::withMessages(['parent_id' => 'A task cannot be its own parent or epic.']);
        }

        if ($parent !== null) {
            $parent->loadMissing('issueType');
            $parentLevel = $this->levelOf($parent);
            if ($level < 2) {
                throw ValidationException::withMessages(['parent_id' => 'Initiatives and epics cannot be sub-tasks; link an epic with epic_id.']);
            }
            if ($parentLevel !== 2) {
                throw ValidationException::withMessages(['parent_id' => 'A parent must be a task, story or bug.']);
            }
        }

        if ($epic !== null) {
            $epic->loadMissing('issueType');
            if ($level === 3 || $level === 0) {
                throw ValidationException::withMessages(['epic_id' => $level === 3
                    ? 'A sub-task takes the epic of its parent.'
                    : 'An initiative cannot be linked to an epic.']);
            }
            if ($this->levelOf($epic) !== $level - 1) {
                throw ValidationException::withMessages(['epic_id' => $level === 1
                    ? 'An epic can only be linked to an initiative.'
                    : 'The epic link must point to an epic.']);
            }
        }
    }

    /** A retyped task must still fit the children already hanging under it. */
    public function assertRetypable(Task $task, IssueType $type): void
    {
        $level = $type->level();

        if ($task->subtasks()->exists() && $level !== 2) {
            throw ValidationException::withMessages(['issue_type_id' => 'This issue has sub-tasks and must stay a task, story or bug.']);
        }

        $linked = Task::query()->where('epic_id', $task->id)->with('issueType')->get();
        foreach ($linked as $child) {
            if ($this->levelOf($child) - 1 !== $level) {
                throw ValidationException::withMessages(['issue_type_id' => 'Issues are linked to this one as their epic; unlink them before changing its type.']);
            }
        }
    }
}
