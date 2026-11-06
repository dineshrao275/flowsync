<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Task;
use App\Models\User;
use App\Support\Like;
use App\Support\VisibleTasks;
use Illuminate\Database\Eloquent\Builder;

/** Find tasks the caller may see. The overdue rule is the dashboard's: open, has a due date, due before today. */
final class SearchTasksTool extends AbstractTool
{
    private const MAX = 20;

    public function name(): string
    {
        return 'search_tasks';
    }

    public function description(): string
    {
        return 'Find tasks the user can see. Use for questions like "my overdue tasks", "what is due this week", "open tasks about login". Returns at most '.self::MAX.' rows plus the real total.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'text' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Words to match in the title, key or description'],
            'assigned_to_me' => ['type' => 'boolean', 'description' => 'Only tasks assigned to the current user'],
            'overdue' => ['type' => 'boolean', 'description' => 'Only open tasks whose due date has passed'],
            'due_within_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 90, 'description' => 'Open tasks due from today through this many days ahead'],
            'state' => ['type' => 'string', 'enum' => ['open', 'done'], 'description' => 'Open (not completed) or done tasks'],
            'project_id' => ['type' => 'integer', 'minimum' => 1],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
        ]);
    }

    public function product(): ?string
    {
        return 'tms';
    }

    public function permission(): ?string
    {
        return 'workspaces.view';
    }

    public function execute(User $user, array $args): ToolResult
    {
        $query = VisibleTasks::query($user, $user->hasPermission('workspaces.manage'))->whereNull('tasks.archived_at');
        $this->filter($query, $user, $args);

        $total = (clone $query)->count();
        $tasks = $query->orderByRaw('tasks.due_date is null')->orderBy('tasks.due_date')->orderBy('tasks.id')
            ->limit((int) ($args['limit'] ?? 10))->get();

        return new ToolResult(
            ['total' => $total, 'shown' => $tasks->count(), 'tasks' => $tasks->map(fn (Task $t) => TaskRows::row($t))->all()],
            $tasks->map(fn (Task $t) => TaskRows::card($t))->all(),
        );
    }

    /** @param array<string,mixed> $args */
    private function filter(Builder $query, User $user, array $args): void
    {
        if (isset($args['text']) && $args['text'] !== '') {
            Like::any($query, ['tasks.title', 'tasks.key', 'tasks.description'], $args['text']);
        }
        if (! empty($args['assigned_to_me'])) {
            $query->where('tasks.assignee_id', $user->id);
        }
        if (isset($args['project_id'])) {
            $query->where('tasks.project_id', $args['project_id']);
        }
        if (($args['state'] ?? null) === 'done') {
            $query->whereNotNull('tasks.completed_at');
        } elseif (($args['state'] ?? null) === 'open') {
            $query->whereNull('tasks.completed_at');
        }
        if (! empty($args['overdue'])) {
            $query->whereNull('tasks.completed_at')->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', now()->toDateString());
        }
        if (isset($args['due_within_days'])) {
            $query->whereNull('tasks.completed_at')->whereNotNull('tasks.due_date')
                ->whereDate('tasks.due_date', '>=', now()->toDateString())
                ->whereDate('tasks.due_date', '<=', now()->addDays($args['due_within_days'])->toDateString());
        }
    }
}
