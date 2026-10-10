<?php

namespace App\Services\Tasks;

use App\Events\TaskSynced;
use App\Models\IssueType;
use App\Models\Label;
use App\Models\Project;
use App\Models\SprintTaskEvent;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\KeyGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a task (with its sub-tasks) to another project of the tenant (P4.6): new key from the
 * target's sequence, statuses remapped (explicit map, else same slug, else same category, else
 * the target default), and every project-bound reference cleared or reconciled. The report says
 * what was dropped so nothing disappears silently.
 */
class TaskMover
{
    public function __construct(
        private readonly KeyGenerator $keys,
        private readonly TaskColumnOrder $columns,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int|string, int|string>  $statusMap  source status id => target status id
     * @return array{task: Task, moved: int, dropped: array<string, int>}
     */
    public function move(Task $task, Project $target, array $statusMap, User $actor, ?string $ip): array
    {
        $source = $task->project;
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target_project_id' => 'The task is already in that project.']);
        }
        if ($target->archived_at !== null) {
            throw ValidationException::withMessages(['target_project_id' => 'Archived projects cannot receive tasks.']);
        }

        $map = $this->validatedMap($target, $statusMap);

        return DB::transaction(function () use ($task, $source, $target, $map, $actor, $ip) {
            $family = collect([$task])->merge($task->subtasks()->orderBy('id')->get());
            $ids = $family->pluck('id')->all();
            $dropped = ['assignee' => 0, 'labels' => 0, 'components' => 0, 'dependencies' => 0, 'epic_links' => 0, 'sprint' => 0, 'watchers' => 0];

            foreach ($family as $member) {
                $this->relocate($member, $source, $target, $map, $ids, $actor, $dropped, $ip);
            }

            $dropped['dependencies'] += $this->dropForeignDependencies($ids);
            $dropped['epic_links'] += Task::query()->whereIn('epic_id', $ids)->whereNotIn('id', $ids)->update(['epic_id' => null]);

            return ['task' => $task->fresh(), 'moved' => $family->count(), 'dropped' => array_filter($dropped)];
        });
    }

    private function relocate(Task $task, Project $source, Project $target, array $map, array $family, User $actor, array &$dropped, ?string $ip): void
    {
        $oldKey = $task->key;
        $oldStatusId = (int) $task->status_id;
        $status = $this->targetStatus($target, $task->status, $map);
        [$key, $sequence] = $this->keys->nextTaskKey($target);

        $assignee = $task->assignee_id !== null ? User::find($task->assignee_id) : null;
        $keepAssignee = $assignee !== null && $target->isMember($assignee);
        $dropped['assignee'] += $task->assignee_id !== null && ! $keepAssignee ? 1 : 0;

        $keepsParent = $task->parent_id !== null && in_array((int) $task->parent_id, $family, true);
        $keepsEpic = $task->epic_id !== null && in_array((int) $task->epic_id, $family, true);
        $dropped['epic_links'] += $task->epic_id !== null && ! $keepsEpic ? 1 : 0;

        if ($task->sprint_id !== null) {
            SprintTaskEvent::create(['sprint_id' => $task->sprint_id, 'task_id' => $task->id, 'type' => 'removed', 'points' => $task->story_points, 'at' => now()]);
            $dropped['sprint']++;
        }

        $retype = $task->parent_id !== null && ! $keepsParent && $task->issueType?->is_subtask;
        $task->forceFill([
            'project_id' => $target->id,
            'workspace_id' => $target->workspace_id,
            'key' => $key,
            'sequence' => $sequence,
            'status_id' => $status->id,
            'position' => $this->columns->nextPosition($status),
            'parent_id' => $keepsParent ? $task->parent_id : null,
            'epic_id' => $keepsEpic ? $task->epic_id : null,
            'assignee_id' => $keepAssignee ? $task->assignee_id : null,
            'issue_type_id' => $retype ? IssueType::where('slug', config('issue_types.default_slug', 'task'))->value('id') : $task->issue_type_id,
            'sprint_id' => null,
            'version_id' => null,
            'completed_at' => $status->is_done ? ($task->completed_at ?? now()) : null,
        ])->save();

        $dropped['components'] += $task->components()->count();
        $task->components()->detach();
        $this->remapLabels($task, $source, $target, $dropped);
        $dropped['watchers'] += $this->pruneWatchers($task, $target);

        TaskStatusHistory::create(['task_id' => $task->id, 'from_status_id' => $oldStatusId, 'to_status_id' => $status->id, 'user_id' => $actor->id, 'changed_at' => now()]);
        $this->activity->log(Task::class, $task->id, 'task.project_changed', ['key' => $key, 'old_key' => $oldKey, 'from_project_id' => $source->id, 'to_project_id' => $target->id], $actor, $ip);
        broadcast(new TaskSynced($task->fresh(), 'created'));
    }

    /** Same workspace keeps its labels; otherwise labels with a same-named twin in the target workspace carry over. */
    private function remapLabels(Task $task, Project $source, Project $target, array &$dropped): void
    {
        if ($source->workspace_id === $target->workspace_id) {
            return;
        }

        $current = $task->labels()->get();
        $twins = Label::where('workspace_id', $target->workspace_id)->whereIn('name', $current->pluck('name'))->pluck('id', 'name');
        $dropped['labels'] += $current->count() - $twins->count();
        $task->labels()->sync($twins->values()->all());
    }

    private function pruneWatchers(Task $task, Project $target): int
    {
        $memberIds = $target->members()->pluck('users.id');
        $stray = $task->watchers()->whereNotIn('users.id', $memberIds)->pluck('users.id');
        $task->watchers()->detach($stray->all());

        return $stray->count();
    }

    /** Dependencies must stay inside one project, so any link leaving the moved set is removed. */
    private function dropForeignDependencies(array $ids): int
    {
        return DB::table('task_dependencies')
            ->where(fn ($q) => $q->whereIn('task_id', $ids)->whereNotIn('depends_on_task_id', $ids))
            ->orWhere(fn ($q) => $q->whereIn('depends_on_task_id', $ids)->whereNotIn('task_id', $ids))
            ->delete();
    }

    private function targetStatus(Project $target, ?TaskStatus $from, array $map): TaskStatus
    {
        $statuses = $target->statuses()->orderBy('position')->get();

        if ($from !== null && isset($map[$from->id])) {
            return $statuses->firstWhere('id', (int) $map[$from->id]);
        }

        return $from === null ? $this->fallback($statuses) : ($statuses->firstWhere('slug', $from->slug)
            ?? $statuses->first(fn (TaskStatus $s) => $s->category === $from->category)
            ?? $this->fallback($statuses));
    }

    private function fallback(Collection $statuses): TaskStatus
    {
        return $statuses->firstWhere('is_default', true) ?? $statuses->first();
    }

    /** @return array<int, int> */
    private function validatedMap(Project $target, array $statusMap): array
    {
        $valid = $target->statuses()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $map = [];
        foreach ($statusMap as $from => $to) {
            if (! in_array((int) $to, $valid, true)) {
                throw ValidationException::withMessages(['status_map' => 'Every mapped status must belong to the target project.']);
            }
            $map[(int) $from] = (int) $to;
        }

        return $map;
    }
}
