<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read model for the project hierarchy tree (P4.1): initiatives -> epics -> issues -> sub-tasks,
 * built from the caller's row-scoped task query so a narrow `tasks.view_*` scope sees only its
 * own slice of every branch. Issues with no epic land under a synthetic "No epic" group.
 */
class HierarchyTree
{
    public const CAP = 2000;

    public function __construct(private readonly TaskReader $reader, private readonly TaskHierarchy $hierarchy) {}

    /** @return array{tree: list<array<string, mixed>>, truncated: bool} */
    public function build(Project $project, User $user): array
    {
        $tasks = $this->reader->filteredQuery($project, [], $user)
            ->reorder()->orderBy('tasks.id')->limit(self::CAP + 1)->get();
        $truncated = $tasks->count() > self::CAP;
        $tasks = $tasks->take(self::CAP);

        $byEpic = $tasks->whereNotNull('epic_id')->groupBy('epic_id');
        $byParent = $tasks->whereNotNull('parent_id')->groupBy('parent_id');
        $roots = $tasks->filter(fn (Task $t) => $this->hierarchy->levelOf($t) === 0 || ($this->hierarchy->levelOf($t) === 1 && ! $t->epic_id));
        $loose = $tasks->filter(fn (Task $t) => $this->hierarchy->levelOf($t) === 2 && ! $t->epic_id && ! $t->parent_id);

        $tree = $roots->values()->map(fn (Task $t) => $this->node($t, $byEpic, $byParent))->all();

        if ($loose->isNotEmpty()) {
            $children = $loose->values()->map(fn (Task $t) => $this->node($t, $byEpic, $byParent))->all();
            $tree[] = ['id' => null, 'key' => null, 'title' => 'No epic', 'virtual' => true, 'children' => $children, 'progress' => $this->progress($loose)];
        }

        return ['tree' => $tree, 'truncated' => $truncated];
    }

    private function node(Task $task, Collection $byEpic, Collection $byParent): array
    {
        $children = collect($byEpic->get($task->id, []))->merge($byParent->get($task->id, []))->unique('id')->values();

        return [
            'id' => $task->id,
            'key' => $task->key,
            'title' => $task->title,
            'level' => $this->hierarchy->levelOf($task),
            'issue_type' => $task->issueType ? ['name' => $task->issueType->name, 'slug' => $task->issueType->slug, 'color' => $task->issueType->color] : null,
            'status' => $task->status ? ['name' => $task->status->name, 'color' => $task->status->color, 'is_done' => (bool) $task->status->is_done] : null,
            'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            'due_date' => $task->due_date?->toDateString(),
            'story_points' => $task->story_points,
            'progress' => $this->progress($children),
            'children' => $children->map(fn (Task $c) => $this->node($c, $byEpic, $byParent))->all(),
        ];
    }

    /** @return array{done: int, total: int} */
    private function progress(Collection $children): array
    {
        return ['done' => $children->filter(fn (Task $t) => $t->completed_at !== null)->count(), 'total' => $children->count()];
    }
}
