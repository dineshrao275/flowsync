<?php

namespace App\Services\Sprints;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\SprintTaskEvent;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Sprint lifecycle (planned → active → completed) and which tasks sit in which sprint. */
class SprintService
{
    /** @param array<string, mixed> $data */
    public function create(Project $project, array $data, int $userId): Sprint
    {
        return $project->sprints()->create($data + ['status' => Sprint::PLANNED, 'created_by' => $userId]);
    }

    public function start(Sprint $sprint): Sprint
    {
        $this->assertStatus($sprint, Sprint::PLANNED, 'Only a planned sprint can be started.');

        if ($sprint->project->sprints()->where('status', Sprint::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['form' => 'This project already has an active sprint. Complete it first.']);
        }

        $start = $sprint->start_date ?? now()->toDateString();
        $sprint->update([
            'status' => Sprint::ACTIVE, 'started_at' => now(),
            'start_date' => $start, 'end_date' => $sprint->end_date ?? now()->addWeeks(2)->toDateString(),
        ]);

        // What is in the sprint at the start is the commitment velocity is measured against.
        $sprint->update(['committed_points' => (float) $sprint->tasks()->sum('story_points')]);

        return $sprint->refresh();
    }

    /**
     * @param  'backlog'|'sprint'  $leftover  where unfinished tasks go
     * @param  int|null  $targetSprintId  required when $leftover is 'sprint'
     */
    public function complete(Sprint $sprint, string $leftover, ?int $targetSprintId): Sprint
    {
        $this->assertStatus($sprint, Sprint::ACTIVE, 'Only the active sprint can be completed.');

        $target = null;
        if ($leftover === 'sprint') {
            $target = $sprint->project->sprints()->where('status', Sprint::PLANNED)->find($targetSprintId)
                ?? throw ValidationException::withMessages(['target_sprint_id' => 'Choose a planned sprint to carry the unfinished work into.']);
        }

        return DB::transaction(function () use ($sprint, $target) {
            $tasks = $sprint->tasks()->with('status')->get();
            $finished = $tasks->filter(fn (Task $t) => $t->status?->is_done);

            $sprint->update(['status' => Sprint::COMPLETED, 'completed_at' => now(), 'completed_points' => (float) $finished->sum('story_points')]);

            foreach ($tasks->reject(fn (Task $t) => $t->status?->is_done) as $task) {
                $this->detach($task, $sprint);
                if ($target) {
                    $this->attach($task, $target);
                }
            }

            return $sprint->refresh();
        });
    }

    public function delete(Sprint $sprint): void
    {
        $this->assertStatus($sprint, Sprint::PLANNED, 'Only a planned sprint can be deleted.');
        $sprint->tasks()->update(['sprint_id' => null]);
        $sprint->delete();
    }

    /** @param list<int> $taskIds */
    public function addTasks(Sprint $sprint, array $taskIds): int
    {
        if ($sprint->status === Sprint::COMPLETED) {
            throw ValidationException::withMessages(['form' => 'A completed sprint cannot take new work.']);
        }

        $tasks = Task::where('project_id', $sprint->project_id)->whereIn('id', $taskIds)->whereNull('parent_id')->get();
        if ($tasks->count() !== count(array_unique($taskIds))) {
            throw ValidationException::withMessages(['task_ids' => 'Only top-level tasks of this project can be planned into a sprint.']);
        }

        DB::transaction(function () use ($tasks, $sprint): void {
            foreach ($tasks as $task) {
                if ((int) $task->sprint_id === $sprint->id) {
                    continue;
                }
                if ($task->sprint_id) {
                    $this->detach($task, $task->sprint);
                }
                $this->attach($task, $sprint);
            }
        });

        return $tasks->count();
    }

    public function removeTask(Sprint $sprint, Task $task): void
    {
        if ((int) $task->sprint_id !== $sprint->id) {
            throw ValidationException::withMessages(['form' => 'That task is not in this sprint.']);
        }
        if ($sprint->status === Sprint::COMPLETED) {
            throw ValidationException::withMessages(['form' => 'A completed sprint is a record; it cannot be changed.']);
        }

        $this->detach($task, $sprint);
    }

    private function attach(Task $task, Sprint $sprint): void
    {
        $task->forceFill(['sprint_id' => $sprint->id])->save();
        SprintTaskEvent::create(['sprint_id' => $sprint->id, 'task_id' => $task->id, 'type' => 'added', 'points' => $task->story_points, 'at' => now()]);
    }

    private function detach(Task $task, ?Sprint $sprint): void
    {
        $task->forceFill(['sprint_id' => null])->save();
        $sprint && SprintTaskEvent::create(['sprint_id' => $sprint->id, 'task_id' => $task->id, 'type' => 'removed', 'points' => $task->story_points, 'at' => now()]);
    }

    private function assertStatus(Sprint $sprint, string $status, string $message): void
    {
        if ($sprint->status !== $status) {
            throw ValidationException::withMessages(['form' => $message]);
        }
    }
}
