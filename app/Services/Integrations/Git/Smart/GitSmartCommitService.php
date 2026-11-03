<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Smart;

use App\Events\CommentSynced;
use App\Models\Comment;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\ActivityLogger;
use App\Services\Integrations\Git\GitIdentityService;
use App\Services\Integrations\Git\Webhook\GitLinker;
use App\Services\PlatformAudit;
use App\Services\Tasks\TaskChangeLogger;
use App\Services\TaskService;
use App\Services\TenantLimits;
use App\Services\Workflow\MoveApprovals;
use App\Services\WorkLogService;
use App\Support\Realtime;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies `#comment`, `#time`, `#close`, `#transition` and `#assign` found in a commit message, as the person the
 * commit author is mapped to - and only with what that person could do in the app. Every change goes through the
 * same services, policies and workflow rules as the UI; a refusal is audited and skipped, never forced.
 */
class GitSmartCommitService
{
    public function __construct(
        private readonly SmartCommitParser $parser,
        private readonly GitIdentityService $identities,
        private readonly GitLinker $linker,
        private readonly TaskService $tasks,
        private readonly TaskChangeLogger $changes,
        private readonly WorkLogService $workLogs,
        private readonly MoveApprovals $approvals,
        private readonly ActivityLogger $activity,
        private readonly PlatformAudit $audit,
        private readonly TenantLimits $limits,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{sha: string, message: string, login: ?string, timestamp: ?string}  $commit
     * @return int commands applied
     */
    public function apply(ProjectRepository $link, Task $task, array $commit, string $delivery): int
    {
        if (! $link->smart_commits_enabled) {
            return 0;
        }
        $commands = $this->parser->parse($commit['message']);
        // One commit is only ever interpreted once per task, however many pushes or merges carry it.
        if ($commands === [] || ! $this->linker->claimOnce($link, $task, 'smart', $commit['sha'])) {
            return 0;
        }

        $actor = $this->identities->userFor($commit['login']);
        if ($actor === null) {
            $this->record('git.smart_commit_skipped', $task, $commit, $delivery, null, null, 'no_identity');

            return 0;
        }

        $applied = 0;
        $timeLogged = false;
        foreach ($commands as $command) {
            if ($command['command'] === 'time' && $timeLogged) {
                continue;
            }
            try {
                $this->run($command, $link, $task->fresh(), $actor, $commit);
                $timeLogged = $timeLogged || $command['command'] === 'time';
                $applied++;
                $this->record('git.smart_commit_applied', $task, $commit, $delivery, $actor, $command['command']);
            } catch (SmartCommitSkipped $e) {
                $this->record('git.smart_commit_skipped', $task, $commit, $delivery, $actor, $command['command'], $e->getMessage());
            } catch (AuthorizationException) {
                $this->record('git.smart_commit_skipped', $task, $commit, $delivery, $actor, $command['command'], 'not_permitted');
            } catch (ValidationException $e) {
                $this->record('git.smart_commit_skipped', $task, $commit, $delivery, $actor, $command['command'], 'rule: '.collect($e->errors())->flatten()->first());
            } catch (Throwable $e) {
                Log::warning('git.smart_commit_failed', ['command' => $command['command'], 'error' => $e::class]);
                $this->record('git.smart_commit_skipped', $task, $commit, $delivery, $actor, $command['command'], 'error');
            }
        }

        return $applied;
    }

    /** @param array{command: string, arg: string} $command */
    private function run(array $command, ProjectRepository $link, Task $task, User $actor, array $commit): void
    {
        match ($command['command']) {
            'comment' => $this->comment($task, $actor, $command['arg']),
            'time' => $this->time($task, $actor, $command['arg'], $commit),
            'close' => $this->transition($link, $task, $actor, null),
            'transition' => $this->transition($link, $task, $actor, $this->parser->statusName($command['arg'])),
            'assign' => $this->assign($task, $actor, $command['arg']),
        };
    }

    private function comment(Task $task, User $actor, string $text): void
    {
        $text = trim(mb_substr($text, 0, SmartCommitParser::COMMENT_MAX));
        $this->allow($actor, 'create', [Comment::class, $task]);
        $this->need($text !== '', 'empty_comment');

        $comment = Comment::create(['task_id' => $task->id, 'user_id' => $actor->id, 'comment' => $text]);
        $this->activity->log(Task::class, $task->id, 'task.commented', ['comment_id' => $comment->id, 'snippet' => mb_strimwidth($text, 0, 120, '…'), 'source' => 'git'], $actor);
        Realtime::send(new CommentSynced($comment, 'created'));
    }

    /** @param array{sha: string, message: string, login: ?string, timestamp: ?string} $commit */
    private function time(Task $task, User $actor, string $arg, array $commit): void
    {
        $this->need($this->limits->hasModule($this->currentTenant(), 'time_tracking'), 'time_tracking_off');
        $this->allow($actor, 'create', [WorkLog::class, $task]);
        $minutes = $this->parser->minutes($arg);
        $this->need($minutes !== null, 'bad_duration');

        $end = ($commit['timestamp'] ?? null) ? Carbon::parse($commit['timestamp']) : now();
        $this->workLogs->create($task, [
            'started_at' => $end->copy()->subMinutes($minutes)->toDateTimeString(), 'ended_at' => $end->toDateTimeString(),
            'description' => 'Logged from commit '.substr($commit['sha'], 0, 8), 'billable' => true,
        ], $actor);
    }

    private function transition(ProjectRepository $link, Task $task, User $actor, ?string $statusName): void
    {
        $this->need($link->smart_commit_transitions_enabled, 'transitions_not_enabled');
        $this->allow($actor, 'move', $task);

        $statuses = $task->project->statuses();
        $target = $statusName === null
            ? (clone $statuses)->where('is_done', true)->orderBy('position')->first()
            : (clone $statuses)->whereRaw('lower(name) = ?', [mb_strtolower($statusName)])->first();
        $this->need($target !== null, 'unknown_status');
        $this->need($target->id !== $task->status_id, 'already_there');
        $this->need(! $this->approvals->required($task, $target, $actor), 'needs_approval');

        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;
        $moved = $this->tasks->move($task, $target->id, null);
        $this->changes->moved($moved, $oldStatusId, $oldStatus, $actor, null);
    }

    private function assign(Task $task, User $actor, string $arg): void
    {
        $this->allow($actor, 'assign', $task);
        $login = $this->parser->login($arg);
        $assignee = $this->identities->userFor($login);
        $this->need($assignee !== null, 'unknown_assignee');

        $oldAssigneeId = $task->assignee_id;
        $oldStatusId = $task->status_id;
        $updated = $this->tasks->update($task, ['assignee_id' => $assignee->id]);
        $this->changes->updated($updated, ['assignee_id' => $assignee->id], $oldAssigneeId, $oldStatusId, $task->status, $actor, null);
    }

    /** @param  array<int, mixed>|mixed  $arguments */
    private function allow(User $actor, string $ability, mixed $arguments): void
    {
        if (! $actor->can($ability, $arguments)) {
            throw new AuthorizationException;
        }
    }

    private function need(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new SmartCommitSkipped($reason);
        }
    }

    private function currentTenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenant->currentId());
    }

    /** @param array{sha: string, message: string, login: ?string, timestamp: ?string} $commit */
    private function record(string $action, Task $task, array $commit, string $delivery, ?User $actor, ?string $command, ?string $reason = null): void
    {
        $this->audit->record(null, $action, 'task', $task->id, array_filter([
            'tenant_id' => $this->tenant->currentId(), 'source' => 'git', 'delivery' => $delivery, 'sha' => substr($commit['sha'], 0, 40),
            'login' => $commit['login'], 'command' => $command, 'reason' => $reason, 'task_key' => $task->key,
            'actor_user_id' => $actor?->id, 'actor_name' => $actor?->name,
        ], fn ($v) => $v !== null));
    }
}
