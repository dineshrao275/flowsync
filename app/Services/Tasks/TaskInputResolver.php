<?php

namespace App\Services\Tasks;

use App\Models\IssueType;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and validates the ids a task write names (status, priority, assignee, parent, labels, components, version, issue type). Moved out of TaskService (P1.7); error messages are unchanged.
 */
class TaskInputResolver
{
    public function status(Project $project, $statusId): TaskStatus
    {
        if ($statusId === null || $statusId === '') {
            return $project->statuses()
                ->where('is_default', true)
                ->first() ?? $project->statuses()->orderBy('position')->first();
        }

        $status = $project->statuses()->find($statusId);

        if ($status === null) {
            throw ValidationException::withMessages([
                'status_id' => 'The selected status does not belong to this project.',
            ]);
        }

        return $status;
    }

    public function priority(Project $project, $priorityId): ?Priority
    {
        if ($priorityId === null || $priorityId === '') {
            return Priority::where('is_default', true)
                ->first();
        }

        $priority = Priority::find($priorityId);

        if ($priority === null) {
            throw ValidationException::withMessages([
                'priority_id' => 'The selected priority does not belong to this tenant.',
            ]);
        }

        return $priority;
    }

    public function assignee(Project $project, $assigneeId): ?User
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }

        $assignee = User::find($assigneeId);

        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee does not belong to this tenant.',
            ]);
        }

        if (! $project->isMember($assignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee must be a member of the project.',
            ]);
        }

        return $assignee;
    }

    public function parent(Project $project, $parentId): ?Task
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = $project->tasks()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'The selected parent task does not belong to this project.',
            ]);
        }

        return $parent;
    }

    /**
     * @return list<int>
     */
    public function labels(Project $project, array $labelIds): array
    {
        $ids = collect($labelIds)->filter()->map(fn ($id) => (int) $id)->values();

        $valid = Label::where('workspace_id', $project->workspace_id)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'labels' => 'One or more selected labels do not belong to this workspace.',
            ]);
        }

        return $valid->all();
    }

    /**
     * @param  array<int, mixed>  $componentIds
     * @return list<int>
     */
    public function components(Project $project, array $componentIds): array
    {
        $ids = collect($componentIds)->filter()->map(fn ($id) => (int) $id)->values();

        $valid = $project->components()->whereIn('id', $ids)->pluck('id');

        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected components do not belong to this project.',
            ]);
        }

        return $valid->all();
    }

    public function version(Project $project, $versionId): ?ProjectVersion
    {
        if ($versionId === null || $versionId === '') {
            return null;
        }

        $version = $project->versions()->find($versionId);

        if ($version === null) {
            throw ValidationException::withMessages([
                'version_id' => 'The selected version does not belong to this project.',
            ]);
        }

        return $version;
    }

    public function issueType($issueTypeId): ?IssueType
    {
        if ($issueTypeId === null || $issueTypeId === '') {
            return IssueType::where('slug', config('issue_types.default_slug', 'task'))->first();
        }

        $type = IssueType::find($issueTypeId);

        if ($type === null) {
            throw ValidationException::withMessages([
                'issue_type_id' => 'The selected issue type does not exist.',
            ]);
        }

        return $type;
    }
}
