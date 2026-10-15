<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Task watcher lifecycle. Moved out of TaskService (P1.7).
 */
class TaskWatchers
{
    public function add(Task $task, User $user): void
    {
        $task->watchers()->syncWithoutDetaching([$user->id => ['created_at' => now()]]);
    }

    public function remove(Task $task, User $user): void
    {
        $task->watchers()->detach($user->id);
    }

    /**
     * @return Collection<int, User>
     */
    public function list(Task $task): Collection
    {
        return $task->watchers()->select(['users.id', 'users.name', 'users.email'])->get();
    }
}
