<?php

namespace App\Services\Automation;

use App\Models\Comment;
use App\Models\Priority;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;

/**
 * Carries out a rule's actions on a task, as the person who created the rule. Every change
 * is logged as an ordinary activity (so the timeline and the event bus see it) tagged with
 * the rule and the automation depth, which is what keeps rules from triggering each other forever.
 */
class ActionRunner
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activities,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return string a one-line description of what was done
     *
     * @throws ValidationException
     */
    public function run(array $action, Task $task, User $actor, int $ruleId, int $depth): string
    {
        $marker = ['automation_rule_id' => $ruleId, 'automation_depth' => $depth + 1, 'key' => $task->key];

        switch ($action['type']) {
            case 'set_assignee':
                $user = $this->resolveUser((string) $action['user'], $task);
                // update() reads a null as "leave it alone"; unassigning is a direct write.
                $user ? $this->tasks->update($task, ['assignee_id' => $user->id]) : $task->update(['assignee_id' => null]);
                $this->log($task, $actor, 'task.updated', ['fields' => ['assignee_id']] + $marker);
                $user && $this->notifications->taskAssigned($actor, $task->refresh(), $user);

                return 'assigned to '.($user?->name ?? 'nobody');

            case 'set_priority':
                $priority = Priority::where('slug', $action['priority'])->first()
                    ?? throw ValidationException::withMessages(['action' => "Unknown priority '{$action['priority']}'."]);
                $this->tasks->update($task, ['priority_id' => $priority->id]);
                $this->log($task, $actor, 'task.updated', ['fields' => ['priority_id']] + $marker);

                return "priority set to {$priority->name}";

            case 'move_to_status':
                $status = $task->project->statuses()->where('slug', $action['status'])->orWhere('id', is_numeric($action['status']) ? $action['status'] : 0)->first()
                    ?? throw ValidationException::withMessages(['action' => 'Unknown status.']);
                $from = $task->status;
                $moved = $this->tasks->move($task, $status->id, null);
                $this->log($task, $actor, 'task.moved', [
                    'from_status_id' => $from?->id, 'from_status' => $from?->name, 'to_status_id' => $status->id, 'to_status' => $status->name,
                    'to_is_done' => $moved->status_id !== $from?->id && (bool) $status->is_done,
                ] + $marker);

                return "moved to {$status->name}";

            case 'add_label':
                $ids = $task->labels()->pluck('labels.id')->all();
                $this->tasks->update($task, ['labels' => array_values(array_unique([...$ids, (int) $action['label']]))]);
                $this->log($task, $actor, 'task.updated', ['fields' => ['labels']] + $marker);

                return 'label added';

            case 'add_comment':
                $comment = Comment::create(['task_id' => $task->id, 'user_id' => $actor->id, 'comment' => $this->template($action['text'], $task)]);
                $this->log($task, $actor, 'task.commented', ['comment_id' => $comment->id, 'snippet' => mb_strimwidth($comment->comment, 0, 120, '…')] + $marker);

                return 'comment added';

            case 'notify':
                $count = 0;
                foreach ($this->recipients($action['to'] ?? ['assignee'], $task) as $user) {
                    $this->notifications->notify($user, 'automation.notice', [
                        'task_id' => $task->id, 'key' => $task->key, 'title' => $task->title, 'project_id' => $task->project_id,
                        'workspace_id' => $task->workspace_id, 'project_name' => $task->project?->name,
                        'message' => $this->template($action['message'], $task),
                    ], null);
                    $count++;
                }

                return "notified {$count} ".($count === 1 ? 'person' : 'people');
        }

        throw ValidationException::withMessages(['action' => 'Unknown action.']);
    }

    private function log(Task $task, User $actor, string $action, array $data): void
    {
        $this->activities->log(Task::class, $task->id, $action, $data, $actor);
    }

    private function resolveUser(string $ref, Task $task): ?User
    {
        return match ($ref) {
            'unassign' => null,
            'reporter' => $task->reporter_user_id ? User::find($task->reporter_user_id) : null,
            'lead' => $task->project->lead_user_id ? User::find($task->project->lead_user_id) : null,
            default => User::find((int) $ref) ?? throw ValidationException::withMessages(['action' => 'Unknown user.']),
        };
    }

    /** @return list<User> */
    private function recipients(mixed $to, Task $task): array
    {
        $users = [];
        foreach ((array) $to as $ref) {
            $user = match ($ref) {
                'assignee' => $task->assignee_id ? User::find($task->assignee_id) : null,
                'reporter' => $task->reporter_user_id ? User::find($task->reporter_user_id) : null,
                'lead' => $task->project->lead_user_id ? User::find($task->project->lead_user_id) : null,
                default => is_numeric($ref) ? User::find((int) $ref) : null,
            };
            $user && $users[$user->id] = $user;
        }

        return array_values($users);
    }

    private function template(string $text, Task $task): string
    {
        $task->loadMissing(['status', 'priority', 'assignee']);

        return strtr($text, [
            '{{key}}' => $task->key, '{{title}}' => $task->title, '{{assignee}}' => $task->assignee?->name ?? 'nobody',
            '{{status}}' => $task->status?->name ?? '', '{{priority}}' => $task->priority?->name ?? '',
        ]);
    }
}
