<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Support\VisibleTasks;
use Illuminate\Support\Str;

/** One task by key (e.g. WR-12), only if the caller can see it. A task they cannot see answers exactly like one that does not exist. */
final class GetTaskTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_task';
    }

    public function description(): string
    {
        return 'Get the details of one task by its key, such as WR-12.';
    }

    public function parameters(): array
    {
        return $this->schema(['key' => ['type' => 'string', 'maxLength' => 40]], ['key']);
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
        $task = VisibleTasks::query($user, $user->hasPermission('workspaces.manage'))
            ->withCount(['subtasks', 'comments'])
            ->where('tasks.key', strtoupper($args['key']))
            ->first();

        if ($task === null) {
            return ToolResult::error("No task '{$args['key']}' that you can access.");
        }

        return new ToolResult(
            TaskRows::row($task) + [
                'description' => Str::limit(strip_tags((string) $task->description), 1200),
                'labels' => $task->labels->pluck('name')->all(),
                'subtasks' => $task->subtasks_count,
                'comments' => $task->comments_count,
                'completed' => $task->completed_at !== null,
            ],
            [TaskRows::card($task)],
        );
    }
}
