<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\GitConnection;
use App\Models\GitIdentity;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 7 - commit-message commands act as the mapped person, through the same rules as the app. */
class GitSmartCommitTest extends TestCase
{
    use IsolatesDatabase;

    private const SECRET = 'whsec_smart';

    private ProjectRepository $link;

    private int $pid;

    /** @var array{id: int, key: string} */
    private array $task;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true, 'git.providers.github.webhook_secret' => self::SECRET]);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $ws = $this->postJson('/api/workspaces', ['name' => 'Smart WS'])->assertCreated()->json('workspace.id');
        $this->pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Smart', 'key' => 'SMT'])->assertCreated()->json('project.id');
        $this->task = $this->postJson("/api/projects/{$this->pid}/tasks", ['title' => 'Smart work'])->assertCreated()->json('task');

        $connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active']);
        GitRepository::create(['git_connection_id' => $connection->id, 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'name' => 'api', 'owner' => 'acme-org', 'available' => true]);
        $this->connectTenant('acme');
        $this->link = ProjectRepository::create([
            'project_id' => $this->pid, 'git_connection_uuid' => $connection->uuid, 'provider' => 'github', 'provider_repo_id' => 42, 'full_name' => 'acme-org/api',
            'html_url' => 'https://github.com/acme-org/api', 'smart_commits_enabled' => true, 'smart_commit_transitions_enabled' => true,
        ]);
        $this->dev = User::where('email', 'admin@flowsync.test')->firstOrFail();
        GitIdentity::create(['user_id' => $this->dev->id, 'provider' => 'github', 'login' => 'octo', 'linked_at' => now()]);
    }

    private function push(string $message, string $sha = 'abc1234', string $login = 'octo', ?string $uuid = null): TestResponse
    {
        $body = (string) json_encode([
            'installation' => ['id' => 501], 'repository' => ['id' => 42, 'full_name' => 'acme-org/api'], 'sender' => ['login' => $login, 'type' => 'User'],
            'ref' => 'refs/heads/main', 'commits' => [['id' => $sha, 'message' => $message, 'timestamp' => '2026-11-03T10:00:00Z', 'author' => ['username' => $login]]],
        ]);

        return $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => $uuid ?? 'deliv-'.bin2hex(random_bytes(6)),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function key(): string
    {
        return $this->task['key'];
    }

    private function audits(string $action): int
    {
        return AuditLog::on(config('tenancy.system.connection'))->where('action', $action)->count();
    }

    private function task(): Task
    {
        $this->connectTenant('acme');

        return Task::findOrFail($this->task['id']);
    }

    public function test_comment_time_and_close_run_as_the_mapped_user(): void
    {
        $this->push("{$this->key()} ready #comment looks good #time 1h30m #close");

        $task = $this->task();
        $comment = Comment::where('task_id', $task->id)->firstOrFail();
        $this->assertSame([$this->dev->id, 'looks good'], [$comment->user_id, $comment->comment]);
        $log = WorkLog::where('task_id', $task->id)->firstOrFail();
        $this->assertSame([$this->dev->id, 90], [$log->user_id, $log->duration_minutes]);
        $this->assertTrue((bool) TaskStatus::find($task->status_id)->is_done, '#close moved the task to a done status');
        $this->assertSame(3, $this->audits('git.smart_commit_applied'));
        $this->assertTrue(Activity::where('subject_id', $task->id)->where('action', 'task.moved')->where('actor_user_id', $this->dev->id)->exists());
    }

    public function test_assign_and_transition_by_name(): void
    {
        $other = User::where('email', 'editor@flowsync.test')->firstOrFail();
        GitIdentity::create(['user_id' => $other->id, 'provider' => 'github', 'login' => 'editor-gh', 'linked_at' => now()]);
        $wsId = (int) \App\Models\Project::findOrFail($this->pid)->workspace_id;
        $this->postJson("/api/workspaces/{$wsId}/members", ['user_id' => $other->id, 'role' => 'member'])->assertSuccessful();
        $this->postJson("/api/projects/{$this->pid}/members", ['user_id' => $other->id, 'role_id' => \App\Models\ProjectRole::where('slug', 'developer')->value('id')])->assertSuccessful();
        $statuses = TaskStatus::where('project_id', $this->pid)->orderBy('position')->get();
        $middle = $statuses->first(fn ($s) => ! $s->is_done && $s->id !== $this->task()->status_id) ?? $statuses->last();

        $this->push("{$this->key()} #assign @editor-gh #transition \"{$middle->name}\"");

        $task = $this->task();
        $this->assertSame($other->id, $task->assignee_id);
        $this->assertSame($middle->id, $task->status_id);
    }

    public function test_an_unmapped_author_changes_nothing_and_is_audited(): void
    {
        $this->push("{$this->key()} #comment sneaky #close", 'sha-stranger', 'stranger');

        $task = $this->task();
        $this->assertSame(0, Comment::where('task_id', $task->id)->count());
        $this->assertFalse((bool) TaskStatus::find($task->status_id)->is_done);
        $this->assertSame(1, $this->audits('git.smart_commit_skipped'));
        $this->assertSame(1, \App\Models\TaskCommit::count(), 'the commit is still linked to the task');
    }

    public function test_a_mapped_user_cannot_do_more_than_they_can_in_the_app(): void
    {
        // The viewer role holds no task write grants.
        $viewer = User::where('email', 'viewer@flowsync.test')->firstOrFail();
        GitIdentity::create(['user_id' => $viewer->id, 'provider' => 'github', 'login' => 'viewer-gh', 'linked_at' => now()]);

        $this->push("{$this->key()} #comment hi #close #time 1h", 'sha-viewer', 'viewer-gh');

        $task = $this->task();
        $this->assertSame(0, Comment::where('task_id', $task->id)->count());
        $this->assertSame(0, WorkLog::where('task_id', $task->id)->count());
        $this->assertFalse((bool) TaskStatus::find($task->status_id)->is_done);
        $this->assertSame(0, $this->audits('git.smart_commit_applied'));
        $this->assertGreaterThanOrEqual(1, $this->audits('git.smart_commit_skipped'));
    }

    public function test_everything_is_off_until_the_project_turns_it_on_and_close_has_its_own_switch(): void
    {
        $this->link->update(['smart_commit_transitions_enabled' => false]);
        $this->push("{$this->key()} #comment kept #close");
        $task = $this->task();
        $this->assertSame(1, Comment::where('task_id', $task->id)->count());
        $this->assertFalse((bool) TaskStatus::find($task->status_id)->is_done, '#close needs its own opt-in');

        $this->link->update(['smart_commits_enabled' => false]);
        $this->push("{$this->key()} #comment ignored", 'sha-off');
        $this->assertSame(1, Comment::where('task_id', $this->task()->id)->count());
    }

    public function test_a_commit_is_interpreted_once_however_often_it_arrives(): void
    {
        $this->push("{$this->key()} #time 1h", 'sha-once');
        $this->push("{$this->key()} #time 1h", 'sha-once');
        $this->connectTenant('acme');
        DB::table('task_commits')->delete();   // the commit row is gone, the ledger must still hold
        $this->push("{$this->key()} #time 1h", 'sha-once');

        $this->assertSame(1, WorkLog::where('task_id', $this->task['id'])->count());
    }

    public function test_bad_commands_are_skipped_and_workflow_rules_still_apply(): void
    {
        $this->push("{$this->key()} #time banana #transition Nowhere #assign @nobody #comment ", 'sha-bad');

        $task = $this->task();
        $this->assertSame(0, WorkLog::where('task_id', $task->id)->count());
        $this->assertSame(0, Comment::where('task_id', $task->id)->count());
        $this->assertSame(0, $this->audits('git.smart_commit_applied'));
        $this->assertSame(4, $this->audits('git.smart_commit_skipped'));

        // With enforced workflow and an empty allow-list nothing may move, smart commit or not.
        DB::table('projects')->where('id', $this->pid)->update(['enforce_workflow' => true]);
        $this->push("{$this->key()} #close", 'sha-wf');
        $this->assertFalse((bool) TaskStatus::find($this->task()->status_id)->is_done);
    }

    public function test_time_needs_the_module_and_only_the_first_time_command_counts(): void
    {
        $this->push("{$this->key()} #time 1h #time 2h", 'sha-two');
        $this->assertSame([60], WorkLog::where('task_id', $this->task['id'])->pluck('duration_minutes')->all());

        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());
        \App\Services\TenantLimits::resetMemo();
        config(['git.providers.github.webhook_secret' => self::SECRET]);
        $this->push("{$this->key()} #time 3h", 'sha-three');
        $this->assertSame(1, WorkLog::where('task_id', $this->task['id'])->count());
    }

    public function test_bot_commits_never_run_commands(): void
    {
        $this->push("{$this->key()} #close", 'sha-bot', 'dependabot[bot]');

        $this->assertFalse((bool) TaskStatus::find($this->task()->status_id)->is_done);
        $this->assertSame(0, $this->audits('git.smart_commit_skipped') + $this->audits('git.smart_commit_applied'));
    }
}
