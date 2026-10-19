<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "Last seen": who has opened a task and when. One row per (task, person), refreshed on every open. */
class TaskViews
{
    public function record(Task $task, User $user): void
    {
        if (! Schema::hasTable('task_views')) {
            return;
        }

        DB::table('task_views')->upsert(
            [['task_id' => $task->id, 'user_id' => $user->id, 'last_seen_at' => now()]],
            ['task_id', 'user_id'],
            ['last_seen_at'],
        );
    }

    /** @return list<array{id: int, name: string, last_seen_at: string}> most recent first */
    public function viewers(Task $task): array
    {
        if (! Schema::hasTable('task_views')) {
            return [];
        }

        return DB::table('task_views')
            ->join('users', 'users.id', '=', 'task_views.user_id')
            ->where('task_views.task_id', $task->id)
            ->orderByDesc('task_views.last_seen_at')
            ->limit(50)
            ->get(['users.id', 'users.name', 'task_views.last_seen_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'last_seen_at' => Carbon::parse($r->last_seen_at)->toIso8601String()])
            ->all();
    }
}
