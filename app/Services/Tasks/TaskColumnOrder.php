<?php

declare(strict_types=1);

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
     * Values are inlined as cast ints, not bound: PDO binds every
     * parameter as text and PostgreSQL refuses to store text into the
     * numeric `position` column (42804), while SQLite silently coerces —
     * which is why the suite never caught it. The `(int)` casts make the
     * interpolation injection-free on both grammars.
     *
     * @param  Collection<int, int>  $ids
     */
    private function writePositions(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $cases = [];
        $in = [];

        foreach ($ids as $offset => $id) {
            $id = (int) $id;
            $cases[] = 'when '.$id.' then '.($offset + 1);
            $in[] = $id;
        }

        DB::update(
            'update tasks set position = case id '.implode(' ', $cases).' end where id in ('.implode(',', $in).')',
        );
    }

    public function nextPosition(TaskStatus $status): int
    {
        return $status->tasks()->max('position') + 1;
    }
}
