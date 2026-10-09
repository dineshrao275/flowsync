<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Board column ordering: slot a moved task into a column and write 1..N positions in one UPDATE. Moved out of TaskService (P1.7).
 */
class TaskColumnOrder
{
    public function reorderColumn(Project $project, TaskStatus $status, Task $moved, ?int $index): void
    {
        $ids = $project->tasks()
            ->where('status_id', $status->id)
            ->whereKeyNot($moved->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $position = max(0, min($index ?? $ids->count(), $ids->count()));

        $ids = $ids->slice(0, $position)
            ->concat([$moved->id])
            ->concat($ids->slice($position))
            ->values();

        $this->writePositions($ids);
    }

    /**
     * One UPDATE with CASE id WHEN … THEN … instead of N per-row writes.
     *
     * @param  Collection<int, int>  $ids
     */
    private function writePositions(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($ids as $offset => $id) {
            $cases[] = 'when ? then ?';
            $bindings[] = $id;
            $bindings[] = $offset + 1;
        }

        $placeholders = implode(',', array_fill(0, $ids->count(), '?'));
        $bindings = array_merge($bindings, $ids->all());

        DB::update(
            'update tasks set position = case id '.implode(' ', $cases).' end where id in ('.$placeholders.')',
            $bindings,
        );
    }

    public function nextPosition(TaskStatus $status): int
    {
        return $status->tasks()->max('position') + 1;
    }
}
