<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The canonical "tasks this person may see" query, for any caller that has no HTTP request to
 * lean on (the AI tools, jobs). Tenant admins read every task; everyone else is bound by project
 * membership AND their project role's row scope (TaskScope::constrainToMemberships).
 * `ScopesVisibleTasks` delegates here, so controllers and tools cannot drift apart.
 */
final class VisibleTasks
{
    public static function query(User $user, bool $seesEverything): Builder
    {
        $query = Task::query()
            ->with(['project', 'workspace', 'status', 'priority', 'assignee', 'labels']);

        if (! $seesEverything) {
            TaskScope::constrainToMemberships($query, $user);
        }

        return $query;
    }
}
