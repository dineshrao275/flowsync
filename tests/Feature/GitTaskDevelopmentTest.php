<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\ProjectRepository;
use App\Models\SubscriptionPlan;
use App\Models\TaskBranch;
use App\Models\TaskCommit;
use App\Models\TaskPullRequest;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 6 - the Development section of a task and the project summary behind the board badges. */
class GitTaskDevelopmentTest extends TestCase
{
    use IsolatesDatabase;

    private int $pid;

    private ProjectRepository $link;

    /** @var array{id: int, key: string} */
    private array $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true]);
        $this->login();
        $ws = $this->postJson('/api/workspaces', ['name' => 'Dev WS'])->assertCreated()->json('workspace.id');
        $this->pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Dev', 'key' => 'DEV'])->assertCreated()->json('project.id');
        $this->task = $this->postJson("/api/projects/{$this->pid}/tasks", ['title' => 'Ship it'])->assertCreated()->json('task');

        $connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active']);
        $this->connectTenant('acme');
        $this->link = ProjectRepository::create(['project_id' => $this->pid, 'git_connection_uuid' => $connection->uuid, 'provider' => 'github', 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'html_url' => 'https://github.com/acme-org/api']);
    }

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function pr(int $taskId, int $number, string $state, string $checks = 'none'): TaskPullRequest
    {
        return TaskPullRequest::create([
            'task_id' => $taskId, 'project_repository_id' => $this->link->id, 'number' => $number, 'title' => "PR {$number}", 'state' => $state,
            'checks_state' => $checks, 'html_url' => "https://github.com/acme-org/api/pull/{$number}", 'last_event_at' => now()->addSeconds($number),
        ]);
    }

    public function test_the_task_shows_its_pull_requests_commits_and_branches(): void
    {
        $id = $this->task['id'];
        $this->pr($id, 1, 'merged', 'success');
        $this->pr($id, 2, 'open', 'failure');
        TaskCommit::create(['task_id' => $id, 'project_repository_id' => $this->link->id, 'sha' => str_repeat('a', 40), 'message' => 'Do it', 'author_login' => 'octo', 'committed_at' => now()]);
        TaskBranch::create(['task_id' => $id, 'project_repository_id' => $this->link->id, 'name' => 'feature/DEV-1']);
        TaskBranch::create(['task_id' => $id, 'project_repository_id' => $this->link->id, 'name' => 'old', 'deleted_at' => now()]);

        $dev = $this->getJson("/api/projects/{$this->pid}/tasks/$id/development")->assertOk()->json('development');

        $this->assertTrue($dev['has_repository']);
        $this->assertSame([2, 1], array_column($dev['pull_requests'], 'number'), 'newest event first');
        $this->assertSame('failure', $dev['pull_requests'][0]['checks_state']);
        $this->assertSame('acme-org/api', $dev['pull_requests'][0]['repository']);
        $this->assertSame(12, strlen($dev['commits'][0]['sha']), 'short sha');
        $this->assertSame('https://github.com/acme-org/api/tree/feature%2FDEV-1', $dev['branches'][1]['html_url'] ?? $dev['branches'][0]['html_url']);
        $this->assertContains(true, array_column($dev['branches'], 'deleted'));
        $this->assertTrue($dev['can_create_branch']);
    }

    public function test_a_project_with_no_repository_says_so_and_cannot_create_branches(): void
    {
        $ws = $this->postJson('/api/workspaces', ['name' => 'Bare WS'])->assertCreated()->json('workspace.id');
        $bare = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Bare', 'key' => 'BAR'])->assertCreated()->json('project.id');
        $task = $this->postJson("/api/projects/$bare/tasks", ['title' => 'x'])->assertCreated()->json('task.id');

        $this->getJson("/api/projects/$bare/tasks/$task/development")->assertOk()
            ->assertJsonPath('development.has_repository', false)->assertJsonPath('development.can_create_branch', false);
    }

    public function test_commits_are_capped_at_the_newest_twenty(): void
    {
        foreach (range(1, 25) as $i) {
            TaskCommit::create(['task_id' => $this->task['id'], 'project_repository_id' => $this->link->id, 'sha' => sprintf('%040d', $i), 'message' => "c{$i}", 'committed_at' => now()->addMinutes($i)]);
        }

        $commits = $this->getJson("/api/projects/{$this->pid}/tasks/{$this->task['id']}/development")->json('development.commits');

        $this->assertCount(20, $commits);
        $this->assertSame('c25', $commits[0]['message']);
    }

    public function test_a_task_from_another_project_is_not_reachable_through_this_one(): void
    {
        $ws = $this->postJson('/api/workspaces', ['name' => 'Other WS'])->assertCreated()->json('workspace.id');
        $other = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Other', 'key' => 'OTH'])->assertCreated()->json('project.id');

        $this->getJson("/api/projects/$other/tasks/{$this->task['id']}/development")->assertNotFound();
    }

    public function test_the_summary_rolls_open_pull_requests_up_to_the_worst_checks_state(): void
    {
        $other = $this->postJson("/api/projects/{$this->pid}/tasks", ['title' => 'Quiet'])->assertCreated()->json('task.id');
        $this->pr($this->task['id'], 1, 'open', 'success');
        $this->pr($this->task['id'], 2, 'open', 'failure');
        $this->pr($this->task['id'], 3, 'merged', 'pending');
        $this->pr($other, 4, 'merged', 'failure');

        $summary = $this->getJson("/api/projects/{$this->pid}/development/summary")->assertOk()->json();

        $this->assertSame(['pull_requests' => 3, 'open' => 2, 'checks_state' => 'failure'], $summary['tasks'][$this->task['id']]);
        $this->assertSame('none', $summary['tasks'][$other]['checks_state'], 'a merged PR no longer decides the badge');
        $this->assertSame('acme-org/api', $summary['repositories'][0]['full_name']);
        $this->assertSame(4, $summary['repositories'][0]['last_pull_request']['number']);
    }

    public function test_it_needs_git_view_the_module_and_task_access(): void
    {
        $id = $this->task['id'];
        $this->postJson('/api/auth/logout');

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/projects/{$this->pid}/tasks/$id/development")->assertForbidden();
        $this->getJson("/api/projects/{$this->pid}/development/summary")->assertForbidden();
        $this->postJson('/api/auth/logout');

        // Editor has git.view but is not a member of this project: the project/task policy still decides.
        $this->login('editor@flowsync.test');
        $this->getJson("/api/projects/{$this->pid}/tasks/$id/development")->assertForbidden();
        $this->postJson('/api/auth/logout');

        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());
        $this->login();
        $this->getJson("/api/projects/{$this->pid}/development/summary")->assertForbidden()->assertHeader('X-Module-Denied', 'git');
    }
}
